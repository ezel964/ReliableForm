# ReliableForm

A deliberately simple JotForm-style form builder with a deliberately *real*
production topology. ReliableForm exists so we can define, implement, and break
SRE things (SLIs, health checks, queues, load balancing, failure modes) on a
stack we fully control — without touching the real product.

**The stack is the point:**

```
                      ┌──────────────────────────┐
 browser ──:8080──▶   │  Nginx (load balancer)   │
                      └────┬──────────┬──────────┘
               /status,/api/*         │ everything else (least_conn)
                      ┌────▼─────┐ ┌──▼───────┐ ┌──────────┐
                      │ status    │ │ web1     │ │ web2     │
                      │ Node :9301│ │ PHP :9001│ │ PHP :9002│
                      └──┬───┬────┘ └──┬───┬───┘ └──┬───┬───┘
                         │   │         │   │        │   │
                   MySQL ◀───┼─────────┘   └───┬────┘   │
                    :3306    │                 │        │
                             ▼                 ▼        ▼
                          Redis :6379  (sessions · cache · queues · heartbeats)
                             ▲               ▲                ▲
                      ┌──────┴─────┐  ┌──────┴───────┐  ┌─────┴──────────┐
                      │ pdf worker │  │ email worker │  │ webhook worker │
                      │  PHP CLI   │  │   PHP CLI    │  │    PHP CLI     │
                      └────────────┘  └──────────────┘  └────────────────┘
```

Two identical PHP web instances behind Nginx (sessions live in Redis, so
either instance can serve you — the page footer tells you which one did), a
Node status/analytics service, and three PHP CLI workers consuming Redis
queues: every submission asynchronously produces a **PDF report**
(`storage/pdfs/`), an **email notification** (written as `.eml` files to
`storage/mail/` — nothing is ever really sent), and — when the form has one —
a **webhook delivery** (JSON POST with retries and a dead-letter queue).

## Setup — Podman (required)

The stateful tier — **MySQL, Redis, gearmand** — runs in [Podman](https://podman.io)
containers (`config/podman/compose.yaml`), driven by `setup.sh`/`launch.sh`
through `podman compose`. **Docker is not used.** Install Podman and a compose
provider before the first run:

```bash
# macOS
brew install podman podman-compose
podman machine init && podman machine start

# Linux (Debian/Ubuntu)
sudo apt-get install podman podman-compose
```

Verify compose works:

```bash
podman compose version    # or: podman-compose version
```

The containers publish to the **same `127.0.0.1` ports** the app expects
(3306/6379/4730). If you already run host Redis or MySQL (e.g. via Homebrew),
stop them first or Podman will fail to bind:

```bash
brew services stop redis mysql    # macOS; Linux: sudo systemctl stop redis-server mysql
```

`INFRA_RUNTIME=podman` is the default in `.env`. Set `INFRA_RUNTIME=host` only
when you intentionally want system-installed services instead.

## Quick start

```bash
bash setup.sh    # one time: checks/installs deps (asks first), creates DB, seeds demo data
bash launch.sh   # starts everything, prints a ✓/✗ health table
```

Both scripts are interactive — if Nginx/PHP/Node (or Podman, in the default
mode) are missing they say so and offer to fix it (Homebrew on macOS, apt on
Linux). Add `--yes` to either script for non-interactive/CI use. Schema
changes ship as `db/migrations/*.sql`; re-running `setup.sh` applies pending
ones (see [docs/RUNBOOK.md](docs/RUNBOOK.md)).

`INFRA_RUNTIME` in `.env` (env var overrides) selects the backing-service mode:

| value | behavior |
|---|---|
| `podman` (default) | require Podman; on macOS offers to start the `podman machine`; offers a host fallback only if Podman is unavailable |
| `auto` | use Podman when usable; otherwise show a red-box warning and fall back to host-installed MySQL/Redis/gearmand |
| `host` | system-installed services via brew/systemctl (no Podman) |

The **app tier (web1/web2, status, workers, nginx) stays as host processes** so
the per-instance chaos-kill drills and php-fpm parity are unaffected. To run the
*entire* stack in Podman instead (portable "run anywhere"), use the optional
full-stack compose:

```bash
bash launch.sh full up      # build + start the whole stack in podman
bash launch.sh full down    # tear it down (named volumes preserved)
```

First run pulls the MySQL/Redis images and builds the small gearmand/PHP images
(cached afterward, so steady-state startup stays fast). Stop the podman backing
tier with `bash launch.sh stop --infra` (plain `stop` leaves it running, like
system services).

Test the stack you just launched ([docs/TESTING.md](docs/TESTING.md)):

```bash
bash tests/run-unit.sh           # PHP unit tests
(cd apps/status && npm test)     # Node unit tests
(cd frontend && pnpm -r test)    # frontend lib tests (vitest)
bash tests/e2e.sh                # end-to-end regression gate (live stack)
```

Optional — browser RUM, trace propagation, and JS error reporting on the
existing PHP pages (requires Node 18+ and pnpm; the PHP app runs fine without
it):

```bash
cd frontend && pnpm install && pnpm --filter @rf/telemetry-entry build
bash launch.sh restart           # re-render nginx so /build/ is served
```

Telemetry scripts are injected only when `frontend/build/asset-manifest.json`
exists (built output from `@rf/telemetry-entry`).

Then open:

| URL | What |
|---|---|
| http://localhost:8080 | The app (through the load balancer) |
| http://localhost:8080/status | Live status dashboard (per-service health, queue depths, worker heartbeats, stats) |
| http://localhost:8080/api/status | The same, as JSON |
| http://localhost:8080/api/stats | Usage analytics JSON |
| http://localhost:8080/v1/forms | REST API v1 (Bearer key auth — see [docs/API.md](docs/API.md)) |

**Demo login:** `demo@reliableform.dev` / `demo1234` — comes with a seeded
"Customer Feedback" form at http://localhost:8080/f/demofeedbk showing nine
of the ten widgets (text, textarea, email, number, select, radio, checkbox,
date, star rating — plus a file-upload widget you can add in the builder).

`launch.sh` also supports `stop` (`--infra` also stops the podman backing
services), `restart`, `status`, `logs <service>`, `kill <service>` (services:
`web1 web2 status worker-pdf worker-email worker-webhook otel nginx gearmand`),
and `full up|down` (run the whole stack in podman).

## What you can do

- Register, log in/out (Redis-backed sessions shared across both web instances).
- Build forms in a drag-free, very readable builder: add any of the 10 widget
  types, edit labels/placeholders/options/required, reorder, live preview.
- Share the public link (`/f/<public_id>`), collect submissions — including
  file uploads (write-only: ≤ 2 MB, extension whitelist, stored under
  `storage/uploads/`, never served back over HTTP).
- Watch each submission flow through the system: row in MySQL → jobs on
  `queue:pdf` / `queue:email` (+ `queue:webhook`) → workers pick them up
  (heartbeats on the status page) → PDF download appears on the submission
  page, `.eml` lands in `storage/mail/`.
- Use the REST API v1 — same submission pipeline, key auth, per-key rate
  limit ([docs/API.md](docs/API.md)):

  ```bash
  curl http://localhost:8080/v1/forms \
    -H "Authorization: Bearer deadbeefdeadbeefdeadbeefdeadbeefdeadbeef"
  ```

- Point a form's webhook (builder → Integrations) at any URL and get each
  submission POSTed as JSON — 2s/5s timeouts, 3 attempts with backoff,
  dead-letter queue, test sink in `tools/webhook-sink.php`.
- Export a form's submissions as CSV (`/forms/{id}/export.csv` — RFC-4180,
  spreadsheet formula-injection guard).

## SRE hooks (why this exists)

- **Health:** every HTTP service exposes `/healthz` (checks its real
  dependencies, 200/503). Nginx ejects a failing web instance
  (`max_fails=3 fail_timeout=5s`) and retries the other (`proxy_next_upstream`).
- **Liveness:** workers SETEX `heartbeat:<instance>` every loop; the status
  page calls a worker dead when the key expires.
- **Metrics:** every request/job emits statsd-format UDP (`reliableform.*`
  counters/timings — see `lib/Metrics.php`) to `STATSD_HOST:STATSD_PORT`.
  Point your SLI library or any statsd listener at it; with no listener the
  packets just vanish, by design.
- **Observability surfaces:** nginx access log has `rt=`/`urt=`/`upstream=`/
  `rid=` per request (`storage/logs/nginx-access.log`); every response carries
  `X-Served-By: web1|web2` and an `X-Request-Id` from nginx.
- **Traces:** W3C trace context end to end — incoming `traceparent` adopted,
  every response carries `X-Trace-Id`, the context rides queue jobs into the
  workers and leaves the stack again on webhook deliveries (`traceparent` to
  your receiver). Spans spool locally and ship via the `otel` sidecar; enable
  with one line in `.env`:
  `OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318`. When the frontend
  telemetry bundle is built, browser fetch/XHR to same-origin APIs also carry
  `traceparent` (custom header — no browser OpenTelemetry SDK) so RUM and API
  calls join the same trace.
- **Browser telemetry (optional — requires `frontend/` build):** the
  `@rf/telemetry` bundle injects on every PHP page when
  `frontend/build/asset-manifest.json` exists. Core Web Vitals beacon to
  `POST /v1/rum` (StatsD timings `web.client.lcp|inp|cls|ttfb|fcp|fid`; CLS
  scaled ×1000; server-side sampling via `RUM_SAMPLE_RATE`). JS errors and
  unhandled rejections beacon to `POST /v1/clientlog` (AppLog `client_error`,
  StatsD `web.client.error`; gated by `CLIENTLOG_ENABLED`, per-IP rate limit
  `CLIENTLOG_RATE_PER_MIN`). Initial frontend SLO targets (LCP/INP/CLS/TTFB,
  JS error rate, submit/save success) live in
  [docs/SRE-GUIDE.md](docs/SRE-GUIDE.md); metric names in
  [docs/METRICS.md](docs/METRICS.md).
- **Structured logs:** one JSON line per request/job in
  `storage/logs/app-<instance>.jsonl`, each carrying `trace_id`
  (`APPLOG_ENABLED=0` turns it off).
- **Load generator:** `bash scripts/loadgen.sh -d 60 -c 4` — realistic mix
  against the LB, server-side latency percentiles from the nginx log
  (overhead A/B protocol in [docs/SRE-GUIDE.md](docs/SRE-GUIDE.md)).
- **Queues:** depth, dead-letter list (`queue:dead`), retry with backoff
  (3 attempts), idempotent jobs.

### Failure-mode experiments to try

```bash
bash launch.sh kill web1                # LB keeps serving via web2; /status flags web1
redis-cli -h 127.0.0.1 shutdown nosave  # sessions/queues degrade; healthz → 503; workers survive and reconnect
bash launch.sh kill worker-pdf          # PDFs queue up; heartbeat vanishes on /status; restart drains the backlog
php -S 127.0.0.1:9444 tools/webhook-sink.php   # then set a form's webhook to
                                        # http://127.0.0.1:9444/hook?fail=1 and submit:
                                        # watch 3 attempts fail in the sink log, the job
                                        # dead-letter to queue:dead, the row turn 'failed'
```

(`bash launch.sh start` brings anything you killed back — `kill` accepts
every service name, including `status` and `worker-webhook`. Webhook drill
recipes incl. deterministic retry-then-deliver: [docs/RUNBOOK.md](docs/RUNBOOK.md).)

## Layout

```
apps/web/      PHP web app — routes in public/index.php; api/rum.php, api/clientlog.php
               (sessionless telemetry beacons); views/layout.php injects browser
               telemetry when built; FrontendLoader hosts future React SPAs
apps/status/   Node status & analytics service (dep: mysql2 only)
apps/workers/  pdf_worker.php, email_worker.php, webhook_worker.php,
               otel_shipper.php (PHP CLI loops)
frontend/      optional pnpm workspace (Rspack + TypeScript): shared libs
               (@rf/router-bridge, @rf/request-layer, @rf/telemetry) and
               @rf/telemetry-entry → revision-hashed bundles in frontend/build/
lib/           shared PHP kernel: Config, DB, RedisClient (pure-PHP RESP),
               Session (Redis), Auth, Csrf, Queue, Metrics, Pdf (dependency-free
               PDF writer), Trace, Otel, AppLog, FrontendLoader, helpers
config/        nginx.conf.template (rendered to storage/run/nginx.conf at launch;
               serves /build/ from frontend/build/)
db/            schema.sql + seed.sql (idempotent) + migrations/ (applied by setup.sh)
tools/         webhook-sink.php — local webhook receiver with failure-drill modes
tests/         PHP/Node/frontend unit suites + e2e regression gate (docs/TESTING.md)
docs/          operator & developer docs (index below)
storage/       runtime: logs/, run/ (pids+conf), pdfs/, mail/, uploads/
```

## Docs

| doc | what |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | the full contract: ports, Redis keys, queue protocol, route table, JSON shapes |
| [docs/API.md](docs/API.md) | REST API v1 + webhooks + CSV export reference |
| [docs/RUNBOOK.md](docs/RUNBOOK.md) | operations: drills, queue/webhook surgery, migrations, logs |
| [docs/SRE-GUIDE.md](docs/SRE-GUIDE.md) | telemetry layers, trace model, SLI candidates, overhead A/B protocol |
| [docs/METRICS.md](docs/METRICS.md) | every metric, log event, and span that exists |
| [docs/TESTING.md](docs/TESTING.md) | unit/node/e2e test suites and how to run them |

PHP: no composer, no PHP frameworks. Node status service: one npm dependency.
An optional `frontend/` pnpm workspace (Rspack + TypeScript) builds browser
telemetry today and will host React SPAs for the builder and dashboard in later
plans; public forms stay server-rendered PHP. The PHP app runs standalone —
the frontend build is additive, and telemetry is injected only when built.
Everything is small and readable on purpose — it's a lab bench, not a product.
