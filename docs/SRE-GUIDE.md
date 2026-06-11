# ReliableForm — SRE Guide

Why this playground exists and how to use it for the SLI library work.

See also: [API.md](API.md) (API v1 + webhooks reference) ·
[RUNBOOK.md](RUNBOOK.md) (operations, drills, queue surgery) ·
[METRICS.md](METRICS.md) (full telemetry catalog) ·
[TESTING.md](TESTING.md) (test suites).

## Purpose

ReliableForm is the staging ground for our in-house SLI stack: deploy it here
first, **measure its overhead**, and **validate the data it collects** against
a topology we fully control — before any of it touches production JotForm. The
stack is deliberately real (LB → replicated web tier → async workers →
queue/DB) so SLI definitions, collection paths, and failure behavior can be
exercised end to end, including under induced failures (`launch.sh kill`,
Redis outages — see the RUNBOOK drills).

## The three telemetry layers

Each layer is independently toggleable from `.env` so its cost can be isolated:

| layer | knob (`.env`) | off-cost | what it emits |
|---|---|---|---|
| statsd metrics | always on (fire-and-forget UDP) | — | `reliableform.*` counters/timings to `STATSD_HOST:STATSD_PORT` (default 127.0.0.1:8125). No listener ⇒ packets vanish, by design. Full list in [METRICS.md](METRICS.md). |
| structured app log | `APPLOG_ENABLED` (default `1`; `0` disables) | zero — the guard short-circuits | one JSON line per web request (`http_request`) and per worker job (`job`) to `storage/logs/app-<instance>.jsonl`, each carrying `trace_id` |
| OTel traces | `OTEL_EXPORTER_OTLP_ENDPOINT` (unset ⇒ fully disabled) | zero — no buffering, no spool writes | one SERVER span per web/status request + one CONSUMER span per worker job + one CLIENT span per webhook delivery attempt, spooled to `storage/run/otel-spool/<instance>.jsonl`, shipped by the `otel` sidecar |

`API_RATE_LIMIT_PER_MIN` is not in `.env.example` — the absent key means the
default (`120` per key per minute; `0` disables). Add the line to override.

Apps never export telemetry over HTTP themselves: statsd is UDP
fire-and-forget, spans go to a local spool file. The only process talking to a
collector is the `otel` sidecar — by design, so exporter cost never pollutes
request-path measurements.

## Connecting an OpenTelemetry collector

1. Set the endpoint in `.env`:

   ```
   OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
   ```

2. Restart the stack — this also starts the `otel` sidecar, which only runs
   when the endpoint is set:

   ```bash
   bash launch.sh restart
   ```

3. The sidecar wakes every ~2s, renames each spool file to a `.shipping.<ts>`
   handoff (writers just create a fresh spool on their next append), groups
   lines by service+instance, and POSTs OTLP/HTTP JSON to
   `$OTEL_EXPORTER_OTLP_ENDPOINT/v1/traces`. Failed POSTs are retried; a
   handoff file older than 120s is dropped (counter `otel.spans_dropped`).

Minimal collector config to see the spans (illustration — any OTLP/HTTP
receiver works):

```yaml
# otelcol.yaml — run: otelcol --config otelcol.yaml
receivers:
  otlp:
    protocols:
      http:
        endpoint: 127.0.0.1:4318
exporters:
  debug:
    verbosity: detailed
service:
  pipelines:
    traces:
      receivers: [otlp]
      exporters: [debug]
```

Sanity check without a collector binary: `nc -l 4318` shows the raw POSTs
(they will fail and retry — fine for a quick look).

## Trace model

W3C Trace Context, no SDK:

- An incoming `traceparent` header is adopted (else a fresh trace starts);
  every web/status response carries `X-Trace-Id`.
- The web request emits **one SERVER span** named `<METHOD> <route-pattern>`
  (e.g. `POST /f/{public_id}`) from a shutdown function.
- `Queue::push()` embeds the current context as the job's `trace` field
  (`{"trace_id","span_id"}`); the worker resumes it with
  `Trace::startFromJob()` and emits **one CONSUMER span** per job
  (`queue:pdf process` / `queue:email process` / `queue:webhook process`),
  parented to the producing request's span.
- The webhook worker adds **one CLIENT span per delivery attempt**
  (`webhook deliver`, parented under the job's CONSUMER span) and sends
  `traceparent` on the outbound POST (same trace id, the CONSUMER span id) —
  the submission trace tree extends into the receiver. The sink
  (`tools/webhook-sink.php`) logs the incoming `traceparent` as propagation
  proof.
- nginx's `X-Request-Id` is recorded as span attribute `app.request_id` and
  app-log field `request_id`, so the access-log line (`rid=`) joins the trace.

A complete submission trace (web or API submit) looks like:

```
trace b13e71c236e21f6a31b473259882deee
└── POST /v1/forms/{public_id}/submissions  SERVER    reliableform-web (web2)
    ├── queue:pdf process       CONSUMER  reliableform-worker-pdf
    ├── queue:email process     CONSUMER  reliableform-worker-email
    └── queue:webhook process   CONSUMER  reliableform-worker-webhook
        ├── webhook deliver     CLIENT    reliableform-worker-webhook   (one per attempt)
        └── ⇣ receiver spans (the POST carries traceparent = the CONSUMER span)
```

The same `trace_id` appears in `app-web1.jsonl` (the `http_request` line) and
in `app-worker-*.jsonl` (the `job` lines) — traces and logs join with no
collector at all.

## SLI candidates per service

| SLI | service | source(s) | notes |
|---|---|---|---|
| availability (non-5xx ratio) | web tier | nginx access log `$status`, or `reliableform.web.status.<code>` counters | LB-side `$status` includes nginx-generated 502s the app never saw — prefer it |
| web request latency | web tier | `reliableform.web.request_ms` timing, or nginx `rt=` | `rt=` includes nginx + retry time; `web.request_ms` is app-only. loadgen reports `rt=` percentiles |
| API availability (non-5xx ratio) | web tier (`/v1`) | `reliableform.api.status.<code>` counters | the API is its own metric family — v1 traffic never counts into `web.*` (one family per request) |
| API latency | web tier (`/v1`) | `reliableform.api.request_ms` timing | app-side; nginx `rt=` covers it too (filter access log on `/v1/`) |
| API saturation | web tier (`/v1`) | `reliableform.api.ratelimited` counter | rejected-per-key 429s; limiter fails open on Redis errors, so 0 can also mean "limiter offline" |
| webhook delivery success rate | worker-webhook | `reliableform.worker.webhook.delivered` vs `worker.webhook.failed` | `failed` counts per failed *attempt* (like pdf/email); a retry-then-success run is 1 failed + 1 delivered. Row-level truth: `webhook_deliveries.status` |
| webhook delivery latency | worker-webhook | `reliableform.worker.webhook.job_ms` timing, or `webhook_deliveries.duration_ms` | `job_ms` = successful job (incl. payload build); `duration_ms` = the HTTP attempt only |
| end-to-end submission→delivered | cross-service | submission SERVER span start → `webhook deliver` CLIENT span end, joined by `trace_id` | also collector-free: `http_request` line → `job` line (`queue:webhook`, outcome `done`) in `app-*.jsonl`, same `trace_id` |
| status API latency | status | `reliableform.status.probe_ms` (probe sweep), SERVER span duration | probe sweep is the expensive path |
| worker freshness | workers | `heartbeat:worker-*` key **absent ⇒ down**, plus `LLEN queue:pdf` / `queue:email` depth | depth > 100 is the status page's own alarm threshold |
| job success rate | workers | `reliableform.worker.pdf.done` vs `worker.pdf.failed` (email: `.sent` vs `.failed`) | `failed` increments per failed *attempt*; dead-letters land on `queue:dead` |
| end-to-end submission latency | cross-service | submission `POST /f/{public_id}` SERVER span start → `queue:pdf process` CONSUMER span end, joined by `trace_id` | also computable collector-free from `app-*.jsonl` (`http_request.ts` → `job.ts + dur_ms` with the same `trace_id`) |
| telemetry pipeline health | otel sidecar | `reliableform.otel.spans_exported` vs `otel.spans_dropped`, `heartbeat:otel` | dropped > 0 ⇒ collector outage longer than 120s |

## Overhead measurement methodology (A/B protocol)

Loadgen reports **server-side** latency (nginx `rt=` percentiles over the
run's log window), so client fork overhead never pollutes the numbers. Every
run appends one line to `storage/logs/loadgen-runs.log`:

```
label|date|duration|concurrency|requests|rps|2xx|3xx|4xx|5xx|p50_ms|p90_ms|p99_ms
```

That file is the comparison ledger. Protocol:

1. **Quiet machine.** Close heavy apps; don't compare runs taken under
   different machine load. Keep duration/concurrency identical across the
   whole matrix.

2. **Matrix** — four configurations, edited in `.env`, restart between each
   (`bash launch.sh restart` — workers/status read `.env` at startup):

   | config | `.env` |
   |---|---|
   | `off` (baseline) | `APPLOG_ENABLED=0`, `OTEL_EXPORTER_OTLP_ENDPOINT` unset |
   | `applog` | `APPLOG_ENABLED=1`, OTel unset |
   | `otel-spool` | `APPLOG_ENABLED=1`, `OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318`, **no collector running** (isolates spool-write cost; shipper drops after 120s) |
   | `otel-full` | same endpoint **with** the collector running |

3. **Per configuration**: one warm-up run (discarded — opcache, connection
   pools, page cache), then 3 measured runs:

   ```bash
   bash scripts/loadgen.sh -d 60 -c 4 -l off-warmup
   bash scripts/loadgen.sh -d 60 -c 4 -l off-1
   bash scripts/loadgen.sh -d 60 -c 4 -l off-2
   bash scripts/loadgen.sh -d 60 -c 4 -l off-3
   ```

   Repeat with labels `applog-1..3`, `otel-spool-1..3`, `otel-full-1..3`.

4. **CPU during runs** — sample the interesting pids mid-run:

   ```bash
   ps -o %cpu,rss,command -p "$(cat storage/run/web1.pid)" -p "$(cat storage/run/web2.pid)"
   ps -o %cpu,rss,command -p "$(cat storage/run/otel.pid)"   # otel-* configs only
   ```

5. **Compare medians**, not single runs — take the middle value of the three
   p50s (and p90s) per config:

   ```bash
   grep '^applog-' storage/logs/loadgen-runs.log | awk -F'|' '{print $11}' | sort -n | sed -n 2p
   ```

   The instrumentation delta is `median(p50 config) − median(p50 off)`, same
   for p90/p99. Also compare the `rps` column — overhead can show up as
   throughput loss instead of latency.

6. **Interference notes**: the demo DB grows with every submission run —
   either reseed between configs or accept the slow drift (it affects all
   configs roughly equally if you run them back-to-back). A browser left open
   on `/status` adds an `/api/status` + `/api/stats` request every 5s to the
   log window; close it for clean runs. Don't mix `--reads-only` and default
   runs in one comparison.

## What to look at after a run

```bash
# 1. loadgen's own summary + the ledger line it appended
tail -n 5 storage/logs/loadgen-runs.log

# 2. status page — queue depths back to 0? heartbeats fresh?
open http://localhost:8080/status        # or: curl -s localhost:8080/api/status | jq .

# 3. structured logs — slowest routes of the run
jq -r 'select(.event=="http_request") | "\(.dur_ms) \(.route) \(.status)"' \
    storage/logs/app-web1.jsonl storage/logs/app-web2.jsonl | sort -rn | head

# 4. any jobs that didn't go cleanly
jq -c 'select(.event=="job" and .outcome!="done")' \
    storage/logs/app-worker-pdf.jsonl storage/logs/app-worker-email.jsonl

# 5. dead-letter queue (replay procedure: RUNBOOK.md → Queue surgery)
redis-cli LLEN queue:dead

# 6. follow one submission end to end by trace id
T=$(jq -r 'select(.route=="/f/{public_id}" and .method=="POST") | .trace_id' \
    storage/logs/app-web1.jsonl | tail -1)
grep -h "$T" storage/logs/app-*.jsonl | jq .
```

If the otel layer was on, also check the sidecar kept up: `ls
storage/run/otel-spool/` should be empty-ish (a few in-flight files at most),
and `otel.spans_dropped` should not have fired (watch with `nc -ul 8125`).
