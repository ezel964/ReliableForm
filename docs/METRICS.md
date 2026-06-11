# ReliableForm — Telemetry Catalog

Every metric, structured-log event, span, and observability surface that
exists in this codebase. Names are verified against the source — if it's not
here, nothing emits it.

See also: [API.md](API.md) (API v1 + webhooks reference) ·
[RUNBOOK.md](RUNBOOK.md) (operations) · [SRE-GUIDE.md](SRE-GUIDE.md)
(layer toggles, overhead A/B protocol) · [TESTING.md](TESTING.md) (test suites).

## statsd metrics

Wire format: plain statsd over UDP to `STATSD_HOST:STATSD_PORT` (default
`127.0.0.1:8125`), every name prefixed `reliableform.`:

```
reliableform.web.request:1|c
reliableform.web.request_ms:12.345|ms
```

Counters `|c`, timings `|ms` (3 decimals). `lib/Metrics.php` also supports
gauges `|g`, but **no gauge is emitted anywhere today**. Fire-and-forget: no
listener ⇒ packets vanish; the PHP client silently disables itself for the
rest of the process if the socket can't even be created. Listen ad hoc:

```bash
nc -ul 8125
```

### web.* (PHP web tier — emitted by web1/web2)

| metric | type | when / meaning |
|---|---|---|
| `web.request` | c | every non-`/v1` request entering the front controller (one family per request: `/v1` traffic counts as `api.*` INSTEAD, never both) |
| `web.request_ms` | ms | per request, app-side duration (shutdown function) |
| `web.status.<code>` | c | per request, final HTTP status (e.g. `web.status.200`, `web.status.429`, `web.status.503`) |
| `web.error` | c | uncaught exception in a non-API web request (friendly 500 page shown). The bootstrap handler splits on `RF_API`: `/v1` exceptions count `api.error` instead |
| `web.login.success` | c | successful login |
| `web.login.fail` | c | wrong credentials |
| `web.login.ratelimited` | c | login attempt rejected (> 10/min per IP) |
| `web.register` | c | account created |
| `web.logout` | c | logout |
| `web.csrf.reject` | c | POST with missing/invalid `_token` (419 response) |
| `web.form.create` | c | form created via the builder |
| `web.form.update` | c | form updated (also invalidates `cache:form:*`) |
| `web.form.delete` | c | form deleted |
| `web.submission` | c | public submission accepted (after the DB transaction commits) |
| `web.submission.honeypot` | c | bot tripped the hidden `website` field (faked success, nothing stored) |
| `web.submission.ratelimited` | c | submission rejected with 429 (> `RATE_LIMIT_SUBMIT_PER_MIN` per form+IP) |
| `web.submission.redis_degraded` | c | submission committed but post-commit Redis work (stats INCR + queue pushes) failed |
| `web.submission.upload_store_failed` | c | a validated file upload could not be moved into `storage/uploads/` — the whole submission was rejected (no partial store) |
| `web.apikey.regenerate` | c | API key regenerated from the dashboard (`POST /account/api-key`) |
| `web.export` | c | CSV export streamed (`GET /forms/{id}/export.csv`) |

### api.* (REST API v1 — emitted by web1/web2 for `/v1` requests)

| metric | type | when / meaning |
|---|---|---|
| `api.request` | c | every `/v1` request entering the front controller |
| `api.request_ms` | ms | per `/v1` request, app-side duration (same shutdown function as `web.request_ms`) |
| `api.status.<code>` | c | per `/v1` request, final HTTP status (e.g. `api.status.201`, `api.status.422`, `api.status.429`) |
| `api.error` | c | uncaught exception in a `/v1` request (JSON `internal_error` envelope returned) |
| `api.submission` | c | API submission accepted (after the DB transaction commits) — the API twin of `web.submission` |
| `api.ratelimited` | c | request rejected with 429 (> `API_RATE_LIMIT_PER_MIN` per key) |
| `api.submission.redis_degraded` | c | API submission committed but post-commit Redis work (stats INCR + queue pushes) failed — still 201 |

### queue.* (lib/Queue.php — emitted by whichever process pushes/pops)

| metric | type | when / meaning |
|---|---|---|
| `queue.push.queue_pdf` | c | job pushed to `queue:pdf` (web/API on submission; pdf worker on retry) |
| `queue.push.queue_email` | c | job pushed to `queue:email` (web/API on submission; email worker on retry) |
| `queue.push.queue_webhook` | c | job pushed to `queue:webhook` (web/API on submission to a webhook-enabled form; webhook worker on retry) |
| `queue.push.queue_dead` | c | job dead-lettered after 3 attempts (or a permanently-bad job) |
| `queue.malformed` | c | popped payload was not valid JSON (workers only — they do the popping) |

(Names are the queue key with `:` → `_`.)

### worker.* (PHP CLI workers)

| metric | type | when / meaning |
|---|---|---|
| `worker.pdf.start` | c | pdf worker process started |
| `worker.pdf.done` | c | PDF rendered + row marked `done` |
| `worker.pdf.failed` | c | one failed attempt (also fires for dead-letters; a job retried twice then succeeding counts 2 failed + 1 done) |
| `worker.pdf.job_ms` | ms | successful job duration |
| `worker.email.start` | c | email worker process started |
| `worker.email.sent` | c | `.eml` written + row marked `sent` |
| `worker.email.failed` | c | one failed attempt (same semantics as pdf) |
| `worker.email.job_ms` | ms | successful job duration |
| `worker.webhook.start` | c | webhook worker process started |
| `worker.webhook.delivered` | c | delivery got a 2xx + row marked `delivered` |
| `worker.webhook.failed` | c | one failed attempt (non-2xx/timeout/exception; same semantics as pdf — also fires when a permanently-bad job is dead-lettered) |
| `worker.webhook.job_ms` | ms | successful job duration (payload build + POST + row update) |
| `worker.error` | c | uncaught exception escaped a CLI process entirely (bootstrap handler; the process then exits) |

### otel.* (shipper sidecar)

| metric | type | when / meaning |
|---|---|---|
| `otel.spans_exported` | c | incremented by the number of spans in each successfully POSTed batch |
| `otel.spans_dropped` | c | a `.shipping.*` file older than 120s was discarded; incremented by the file's **line** count (≥1) — an approximation of spans, since one spool line can hold several |

### status.* (Node status service)

| metric | type | when / meaning |
|---|---|---|
| `status.request` | c | every HTTP request to the status service |
| `status.probe_ms` | ms | duration of one full `/api/status` probe sweep (12 probes, ~2s timeout each) |

## Structured log files — `storage/logs/app-<instance>.jsonl`

One JSON line per event, gated by `APPLOG_ENABLED` (default on, `0` disables).
Files: `app-web1.jsonl`, `app-web2.jsonl`, `app-worker-pdf.jsonl`,
`app-worker-email.jsonl`, `app-worker-webhook.jsonl`, `app-status.jsonl`.

Every line carries: `ts` (UTC ISO-8601 with milliseconds), `instance`,
`event`, `trace_id` (32-hex — joins lines across files and joins spans).

**Invariant:** string fields are truncated at 512 chars (`…` appended) so a
line stays far below the 8KB write-atomicity boundary — single-line `O_APPEND`
writes from concurrent processes never interleave.

### `event: "http_request"` (web1/web2 and status)

| field | meaning |
|---|---|
| `method` | HTTP method |
| `route` | route pattern, e.g. `/f/{public_id}` (status service: literal path). `/v1` requests carry the method-prefixed API pattern, e.g. `POST /v1/forms/{public_id}/submissions` (`<v1 404>` / `<v1 405>` for unmatched path/method) |
| `status` | final HTTP status code |
| `dur_ms` | server-side duration, 0.1ms resolution |
| `request_id` | nginx `$request_id` (empty for direct-to-port requests) |
| `user_id` | only present when a logged-in session was already active (PHP web only) |

```json
{"ts":"2026-06-10T21:55:32.107Z","instance":"web1","event":"http_request","trace_id":"8ebc2ffd36d02e70fd4b7103ad125865","method":"POST","route":"/f/{public_id}","status":302,"dur_ms":4.3,"request_id":"c48e026a723de8294d8602f74d9a05ef"}
```

### `event: "job"` (worker-pdf, worker-email, worker-webhook)

| field | meaning |
|---|---|
| `queue` | `queue:pdf`, `queue:email` or `queue:webhook` |
| `submission_id` | the job's submission |
| `attempt` | attempt counter as delivered (0 = first try) |
| `outcome` | `done` \| `retried` \| `dead` \| `failed` |
| `dur_ms` | job processing duration |

```json
{"ts":"2026-06-10T21:55:32.113Z","instance":"worker-pdf","event":"job","trace_id":"8ebc2ffd36d02e70fd4b7103ad125865","queue":"queue:pdf","submission_id":119,"attempt":0,"outcome":"done","dur_ms":6.2}
```

## Spans

Only produced when `OTEL_EXPORTER_OTLP_ENDPOINT` is set. Processes buffer and
append to `storage/run/otel-spool/<instance>.jsonl` (one line =
`{"service","instance","spans":[<OTLP span objects>]}`); the `otel` sidecar
ships them to `<endpoint>/v1/traces` (details in [SRE-GUIDE.md](SRE-GUIDE.md)).

Span objects are OTLP/JSON: `traceId`, `spanId`, optional `parentSpanId`,
`name`, `kind` (enum number), `startTimeUnixNano`/`endTimeUnixNano` (uint64 as
string), typed `attributes`, `status.code` (0 ok / 2 error).

`service.name`: `reliableform-web` (web1/web2), `reliableform-status`,
`reliableform-worker-pdf`, `reliableform-worker-email`,
`reliableform-worker-webhook` — overridable via `OTEL_SERVICE_NAME`;
`service.instance.id` = the instance id.

| span | kind | emitter | name | attributes | error when |
|---|---|---|---|---|---|
| web request | SERVER (2) | web1/web2, one per request (shutdown fn) | `<METHOD> <route-pattern>`, e.g. `GET /f/{public_id}` | `http.request.method`, `http.route`, `http.response.status_code`, `app.request_id`, `app.user_id` (only when a logged-in session was already active) | status ≥ 500 |
| API request | SERVER (2) | web1/web2, one per `/v1` request (same shutdown fn) | the API route pattern (already method-prefixed, not doubled): `GET /v1/me`, `GET /v1/forms`, `GET /v1/forms/{public_id}`, `GET /v1/forms/{public_id}/submissions`, `POST /v1/forms/{public_id}/submissions`, `<v1 404>`, `<v1 405>` | same as web request, minus `app.user_id` (the API path is sessionless) | status ≥ 500 |
| status request | SERVER (2) | status service, one per request | `<METHOD> <route>`, e.g. `GET /api/status` | `http.request.method`, `http.route`, `http.response.status_code`, `app.request_id` | status ≥ 500 |
| worker job | CONSUMER (5) | worker-pdf / worker-email / worker-webhook, one per job, flushed every iteration | `queue:pdf process` / `queue:email process` / `queue:webhook process` | `messaging.destination.name`, `app.submission_id`, `app.attempt`, `app.outcome` (`done`\|`retried`\|`dead`\|`failed`) | outcome ≠ `done` |
| webhook delivery | CLIENT (3) | worker-webhook, **one per delivery attempt** (fresh span id) | `webhook deliver` | `url.full` (origin only — `scheme://host[:port]`, no path, to keep cardinality sane), `http.response.status_code` (0 on transport error/timeout), `app.attempt`, `app.delivery_id` | response not 2xx |

Parenting: a caller's `traceparent` becomes the SERVER span's parent; the
worker CONSUMER span's parent is the producing request's span id (embedded in
the job's `trace` field by `Queue::push()`); the webhook CLIENT span's parent
is its job's CONSUMER span. The delivery's outbound `traceparent` header is
`Trace::traceparent()` — same trace id, CONSUMER span id — so spans the
receiver emits parent under the job span, alongside the CLIENT span.

## Other surfaces

### Redis keys

| key | meaning |
|---|---|
| `heartbeat:<instance>` | SETEX 15s, value = unix ts. Set by `worker-pdf`/`worker-email`/`worker-webhook` (every loop, ≤5s; kept alive through backoff sleeps and immediately after each webhook POST), `status` (every 10s), `otel` (every ~2s loop). **Absent key ⇒ instance down**; age of a present key is informational. |
| `stats:submissions:total` | INCR per accepted submission (web and API) |
| `stats:submissions:<YmdH>` | hourly INCR (UTC bucket), EXPIRE 90000 — feeds `/api/stats` `submissions_last_hour` |
| `queue:pdf`, `queue:email`, `queue:webhook`, `queue:dead` | job lists (RPUSH/BLPOP); `LLEN` = depth |
| `ratelimit:login:<ip>` | INCR+EXPIRE 60; login rejected when > 10 |
| `ratelimit:submit:<public_id>:<ip>` | INCR+EXPIRE 60; 429 when > `RATE_LIMIT_SUBMIT_PER_MIN` (default 30, `0` disables). IP comes from `X-Forwarded-For` ⇒ advisory only |
| `ratelimit:api:<api_key>` | INCR+EXPIRE 60; 429 + `Retry-After: 30` when > `API_RATE_LIMIT_PER_MIN` (default 120, `0` disables). Redis errors fail open |
| `cache:form:<public_id>` | public-form row cache, TTL 60 |
| `sess:<sid>` | session payloads, TTL `SESSION_TTL` |

### nginx access log (`storage/logs/nginx-access.log`, `sre` format)

```
$remote_addr [$time_local] "$request" $status $body_bytes_sent rt=$request_time upstream=$upstream_addr urt=$upstream_response_time rid=$request_id
```

`rt=` total time (s), `urt=` upstream time, `upstream=` which backend,
`rid=` request id (joins `request_id` in app logs and `app.request_id` on spans).

### Response headers

| header | set by | meaning |
|---|---|---|
| `X-Served-By` | web1/web2 and status | which instance answered |
| `X-Trace-Id` | web1/web2 and status | 32-hex trace id of this request |
| `X-Request-Id` | — (request header only) | nginx injects `$request_id` toward upstreams; it is logged (`rid=`) and recorded in telemetry but **not** echoed to clients |
