# ReliableForm — Architecture Contract

ReliableForm is a deliberately simple JotForm-style form builder used as an SRE
playground: a realistic multi-service topology (load balancer, replicated web
tier, async workers, cache/queue, DB) that we instrument with SLIs.

Every component MUST follow this document exactly — it is the integration
contract between independently-built parts.

## Topology

```
                        ┌──────────────────────────┐
   browser ──:8080──▶   │  Nginx (load balancer)   │
                        └────┬──────────┬──────────┘
                 /status,/api/*         │ everything else (least_conn)
                        ┌────▼─────┐ ┌──▼───────┐ ┌──────────┐
                        │ status   │ │ web1     │ │ web2     │
                        │ Node :9301│ │ PHP :9001│ │ PHP :9002│
                        └──┬───┬───┘ └──┬───┬───┘ └──┬───┬───┘
                           │   │        │   │        │   │
                     MySQL ◀───┼────────┘   └───┬────┘   │
                      :3306    │                │        │
                               ▼                ▼        ▼
                            Redis :6379  (sessions, cache, queues, heartbeats)
                               ▲                ▲
                        ┌──────┴─────┐   ┌──────┴───────┐
                        │ pdf worker │   │ email worker │
                        │  PHP CLI   │   │   PHP CLI    │
                        └────────────┘   └──────────────┘
```

Processes started by `launch.sh` (each gets env vars `INSTANCE_ID` and, for web, `PORT`):

| instance id    | what                                      | port  |
|----------------|-------------------------------------------|-------|
| `nginx`        | load balancer / reverse proxy             | 8080  |
| `web1`, `web2` | PHP built-in server, same codebase        | 9001 / 9002 |
| `status`       | Node status & analytics service           | 9301  |
| `worker-pdf`   | PHP CLI loop, consumes `queue:pdf`        | —     |
| `worker-email` | PHP CLI loop, consumes `queue:email`      | —     |
| `worker-webhook` | PHP CLI loop, consumes `queue:webhook`  | —     |
| `otel`         | OTLP shipper sidecar (only when `OTEL_EXPORTER_OTLP_ENDPOINT` is set) | — |

MySQL and Redis are external services (installed/started via setup/launch scripts).

## Directory layout

```
ReliableForm/
├── ARCHITECTURE.md            this file
├── README.md
├── .env.example               copied to .env by setup.sh
├── setup.sh                   one-time interactive installer/bootstrapper
├── launch.sh                  interactive start/stop/status of the whole stack
├── scripts/common.sh          shared bash helpers (colors, OS detect, prompts)
├── config/nginx.conf.template placeholders: {{ROOT}} {{LB_PORT}} {{WEB1_PORT}} {{WEB2_PORT}} {{STATUS_PORT}}
├── db/schema.sql              idempotent (CREATE TABLE IF NOT EXISTS)
├── db/seed.sql                idempotent (INSERT IGNORE) demo user + demo form
├── lib/                       shared PHP kernel (see "Kernel API")
│   ├── bootstrap.php  Config.php  DB.php  RedisClient.php  Session.php
│   ├── Auth.php  Csrf.php  Queue.php  Metrics.php  Pdf.php  helpers.php
├── apps/
│   ├── web/
│   │   ├── public/index.php   front controller + router (also PHP -S router script)
│   │   ├── public/assets/css/app.css
│   │   ├── public/assets/js/app.js, builder.js
│   │   └── src/pages/*.php, src/views/*.php
│   ├── status/server.js       Node service (dep: mysql2 only) + package.json
│   └── workers/pdf_worker.php, email_worker.php
└── storage/                   gitignored runtime data
    ├── pdfs/  mail/  logs/  run/
```

## Configuration — `.env`

Parsed by `lib/Config.php` (PHP) and `apps/status/server.js` (Node, hand-rolled
parser, `KEY=VALUE` lines, `#` comments). All code reads config ONLY through
these. Defaults in parentheses must be assumed when a key is absent.

```
APP_ENV=dev
APP_URL=http://localhost:8080
LB_PORT=8080
WEB1_PORT=9001
WEB2_PORT=9002
STATUS_PORT=9301
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=reliableform
DB_USER=reliableform
DB_PASS=reliableform
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
STATSD_HOST=127.0.0.1
STATSD_PORT=8125
SESSION_TTL=86400
# Observability knobs (iteration 1):
# OTEL_EXPORTER_OTLP_ENDPOINT=   unset ⇒ tracing fully disabled (no spool writes)
# OTEL_SERVICE_NAME=             optional service.name override
APPLOG_ENABLED=1                 # 0 ⇒ no app-*.jsonl writes (overhead A/B knob)
RATE_LIMIT_SUBMIT_PER_MIN=30     # 0 ⇒ submission rate limit off
API_RATE_LIMIT_PER_MIN=120       # per API key; 0 ⇒ off (iteration 2)
```

## MySQL schema (db/schema.sql)

Tables: `users`, `forms`, `submissions`, `pdf_jobs`, `emails`.

- `users(id, email UNIQUE, name, password_hash, created_at)`
- `forms(id, user_id FK, public_id CHAR(10) UNIQUE, title, description,
  fields JSON, is_published TINYINT default 1, created_at, updated_at)`
- `submissions(id, form_id FK, data JSON, ip VARCHAR(45), user_agent
  VARCHAR(255), created_at, KEY (form_id, created_at))`
- `pdf_jobs(id, submission_id FK UNIQUE, status ENUM('pending','processing','done','failed')
  default 'pending', file_path, error TEXT, attempts TINYINT default 0,
  created_at, updated_at)`
- `emails(id, submission_id FK UNIQUE, to_email, status
  ENUM('pending','sent','failed') default 'pending', file_path, error TEXT,
  created_at, updated_at)`

### forms.fields JSON — the widget schema

Array of field objects. `id` is `f_` + 6 random [a-z0-9] chars, generated by the
builder UI, stable across edits.

```json
[
  {"id":"f_a1b2c3","type":"text","label":"Full name","required":true,"placeholder":"Jane Doe"},
  {"id":"f_d4e5f6","type":"select","label":"Department","required":false,"options":["Sales","Support"]},
  {"id":"f_g7h8i9","type":"rating","label":"How did we do?","required":true,"max":5}
]
```

Supported `type` values (the widgets): `text`, `textarea`, `email`, `number`,
`select`, `radio`, `checkbox`, `date`, `rating`, `file`.
`options: string[]` only for select/radio/checkbox; `max: int` (1–10, default 5)
only for rating. `checkbox` submits an array of selected options.

`file` (iteration 2) is WRITE-ONLY: the public form accepts one upload per
file field (≤ 2 MB — nginx already caps the body at 2m — extension whitelist
pdf png jpg jpeg gif txt csv zip, case-insensitive); the server stores it as
`storage/uploads/<form_public_id>/<utcstamp>-<rand8>.<ext>` (sanitized, never
the client filename) and the submission data value is that relative path
string. Uploaded files are NEVER served back over HTTP — the submission detail
page shows path + size as text only. File fields are skipped by the API
(`POST /v1/.../submissions` rejects answers for them with 422 when required,
ignores them otherwise — JSON has no multipart).

`submissions.data` JSON: `{"<field_id>": "value"}` — value is a string, except
checkbox which is `string[]`.

## Redis key conventions

| key                        | type/use                                              |
|----------------------------|-------------------------------------------------------|
| `sess:<sid>`               | PHP session payload, TTL = SESSION_TTL                |
| `queue:pdf`, `queue:email`, `queue:webhook` | job lists — producers RPUSH, workers BLPOP |
| `ratelimit:api:<api_key>`  | INCR + EXPIRE 60; 429 when > API_RATE_LIMIT_PER_MIN (default 120, 0 disables) |
| `queue:dead`               | dead letters (jobs that failed 3 attempts)            |
| `heartbeat:<instance_id>`  | SETEX 15s, value = unix ts; set by workers every loop, by the status service every 10s, and by the otel shipper every cycle. ABSENT key ⇒ instance down (TTL expired); age of a present key is informational only |
| `stats:submissions:total`  | INCR on every submission                              |
| `stats:submissions:<YmdH>` | hourly INCR, EXPIRE 90000. Bucket name is **UTC**: PHP `gmdate('YmdH')`, Node `getUTCFullYear()...getUTCHours()` zero-padded. Never local time |
| `ratelimit:login:<ip>`     | INCR + EXPIRE 60; reject login attempts when > 10     |
| `ratelimit:submit:<public_id>:<ip>` | INCR + EXPIRE 60; 429 when > RATE_LIMIT_SUBMIT_PER_MIN (default 30, 0 disables). XFF-keyed ⇒ advisory only |
| `cache:form:<public_id>`   | JSON form-row cache, TTL 60; DEL on form update       |

### Queue job protocol

Jobs are JSON objects: `{"type":"pdf"|"email","submission_id":123,"attempt":0,"enqueued_at":"<ISO8601>","trace":{"trace_id":"<32hex>","span_id":"<16hex>"}}`
(the `trace` key is attached automatically by `Queue::push()`; workers resume
the trace with `Trace::startFromJob($job)` — consumers must tolerate its absence).

**Row ownership:** the WEB APP creates the `pdf_jobs` and `emails` rows (status
`pending`, `emails.to_email` = the form owner's `users.email`) in the SAME
transaction as the submission insert, and pushes the two queue jobs only after
commit. Workers never INSERT those rows — they only UPDATE status/file_path/
error/attempts (if the row is unexpectedly missing, log + treat as failed job,
do not create it).

**Email transport:** "sending" means writing an RFC-822-style `.eml` file to
`storage/mail/submission-<id>.eml` (From: noreply@reliableform.dev, To: owner,
Subject: New submission on "<form title>", plain-text body listing answers)
and marking the row `sent`. NEVER call `mail()` or attempt real delivery.

Worker loop contract:

1. `Queue::pop($queue, 5)` (BLPOP, 5s timeout — the kernel RedisClient already
   handles the stream-timeout-vs-BLPOP pitfall). On `null`, heartbeat and loop.
2. Process the job **idempotently** — re-running a `done` job must be a no-op
   (check current status first; `submission_id` is UNIQUE).
3. On exception: increment `attempt`; if `attempt >= 3` → `Queue::push(Queue::QUEUE_DEAD, $job + ['error' => $msg])`,
   mark DB row `failed`; else sleep `min(2**attempt, 8)` seconds and re-push to
   the same queue. Also call `DB::reset()` on PDOException so the next attempt
   reconnects.
4. Heartbeat (`heartbeat(INSTANCE_ID)`) at least once per loop iteration.
5. Graceful shutdown: `pcntl_async_signals(true)` + traps for SIGTERM/SIGINT
   (guard with `function_exists('pcntl_signal')`; degrade to a plain loop if
   pcntl is absent) → finish current job, exit 0.

## Kernel API (lib/) — ALREADY IMPLEMENTED, files exist in `lib/`

The kernel is finished and lint-clean. READ the actual files before coding
against them; never redefine or modify them. Highlights already handled for
you: BLPOP raises the socket read timeout itself; `Auth::attempt/register`
call `session_regenerate_id(true)` (fixation defense) and rate-limit by
`request_ip()`; `request_ip()` honors `X-Forwarded-For`; `bootstrap.php` sets
UTC, error handlers, and a friendly 500 page.

Every PHP entry point starts with `require __DIR__ . '/<relative>/lib/bootstrap.php';`
which defines `RF_ROOT`, loads `.env`, registers a PSR-ish autoloader for `lib/`
classes, sets exception/error handlers (log to `storage/logs/php-error.log`,
friendly 500 page on web, counter `*.error` metric).

```php
Config::get(string $key, ?string $default = null): ?string
DB::pdo(): PDO                                  // 3s connect timeout, ERRMODE_EXCEPTION
DB::run(string $sql, array $params = []): PDOStatement
DB::all(string $sql, array $params = []): array
DB::one(string $sql, array $params = []): ?array
DB::insert(string $sql, array $params = []): int   // lastInsertId
RedisClient::instance(): RedisClient            // pure-PHP RESP client, no phpredis needed
  ->command(string ...$args): mixed             // throws RedisException on IO error
  ->get/set/setex/del/incr/expire/llen/rpush/lpush/lrange/ping(...)
  ->blpop(string $key, int $timeout): ?array    // [key, value] or null
Session::start(): void                          // Redis-backed; cookie httponly+samesite=Lax
Auth::user(): ?array      Auth::check(): bool
Auth::requireLogin(): array                     // redirect('/login') + exit when guest
Auth::attempt(string $email, string $password): bool   // rate-limited per IP
Auth::register(string $name, string $email, string $password): array // ['ok'=>bool,'error'=>?string]
Auth::logout(): void
Csrf::token(): string   Csrf::field(): string   // hidden <input>
Csrf::validate(): void                          // call on EVERY POST; 419 + exit on mismatch
Queue::push(string $queue, array $job): void
Queue::pop(string $queue, int $timeout = 5): ?array
Queue::QUEUE_PDF, Queue::QUEUE_EMAIL, Queue::QUEUE_DEAD
Metrics::increment(string $m, int $v = 1): void // statsd UDP "reliableform.<m>:<v>|c", never throws
Metrics::timing(string $m, float $ms): void
Metrics::gauge(string $m, float $v): void
Pdf::class — minimal PDF 1.4 writer:
  $pdf = new Pdf(); $pdf->addPage(); $pdf->heading($t); $pdf->text($t); $pdf->keyValue($k,$v); $pdf->render(): string
// helpers.php (plain functions):
e(?string $s): string                           // htmlspecialchars — use on ALL output
redirect(string $path): never
json_response(array $data, int $status = 200): never
flash(string $type, string $msg): void   flashes(): array   // types: success|error|info
render(string $view, array $vars = []): string  // includes apps/web/src/views/<view>.php
heartbeat(string $instance): void
public_id(): string                             // 10 chars [a-z0-9]
request_ip(): string
instance_id(): string                           // getenv('INSTANCE_ID') ?: 'unknown'
```

## Web app (PHP) — routes

Front controller `apps/web/public/index.php`. Doubles as the `php -S` router
script: requests for existing files under `public/` return `false`. Every
response sends header `X-Served-By: <instance_id>` and statsd metrics
`web.request` (counter), `web.request_ms` (timing), `web.status.<code>` (counter).

| route | behavior |
|---|---|
| `GET /` | landing page; if logged in redirect `/dashboard` |
| `GET/POST /register` | name+email+password (min 8); auto-login on success |
| `GET/POST /login` | email+password; rate-limited; flash on failure |
| `POST /logout` | CSRF-checked; destroy session; redirect `/` |
| `GET /dashboard` | my forms: title, submission count, public link, edit/delete |
| `GET /forms/new` | builder UI (empty) |
| `POST /forms` | create from builder payload |
| `GET /forms/{id}/edit` | builder UI (pre-filled) |
| `POST /forms/{id}` | update; invalidate `cache:form:<public_id>` |
| `POST /forms/{id}/delete` | delete |
| `GET /forms/{id}/submissions` | submissions table + per-submission PDF status |
| `GET /submissions/{id}` | one submission, all answers, PDF/email job status |
| `GET /submissions/{id}/pdf` | `{id}` must be numeric; stream ONLY the DB-stored `file_path` after a `realpath()` prefix check against `storage/pdfs/`; friendly "still rendering" page when not `done` |
| `GET /f/{public_id}` | PUBLIC form page (reads through `cache:form:`) |
| `POST /f/{public_id}` | validate (required, email format, number, allowed options), then in ONE transaction: insert submission + `pending` pdf_jobs row + `pending` emails row (to_email = owner email); after commit INCR stats and push pdf+email jobs (Redis failures here must not 500 the user — flash a warning instead), redirect `/f/{public_id}/thanks` |
| `GET /f/{public_id}/thanks` | thank-you page |
| `GET /healthz` | JSON `{"ok":bool,"instance":"web1","checks":{"db":bool,"redis":bool},"time":...}`; 200 or 503 |
| `GET /forms/{id}/export.csv` | CSV export (iteration 2, see Product surface) |
| `POST /account/api-key` | regenerate the user's API key (CSRF) |
| `* /v1/...` | REST API v1 (iteration 2) — dispatched BEFORE the CSRF guard, sessionless |

Ownership checks: all `/forms/*` and `/submissions/*` routes verify the form
belongs to `Auth::user()` (404 otherwise). The builder posts the full field
array as a JSON string in hidden input `fields_json`; the server re-validates
strictly: payload ≤ 64 KB; 1–50 fields; `id` matches `^f_[a-z0-9]{6}$` and is
unique within the form; `type` in the whitelist; `label` non-empty ≤ 200 chars;
`options` only for select/radio/checkbox, 1–20 options, each non-empty ≤ 100
chars; `rating.max` int 1–10 (default 5). Reject the whole payload on any
violation (flash + redisplay), never silently "fix" it.

## Status service (Node) — endpoints

`apps/status/server.js`, Node 18+, the only npm dependency is `mysql2`
(`require('mysql2/promise')`). Redis via a tiny inline RESP client (TCP socket).
Must start (with degraded output) even when MySQL/Redis are down.

| route | behavior |
|---|---|
| `GET /healthz` | `{"ok":true,"instance":"status"}` |
| `GET /status` | human dashboard (self-contained HTML, auto-refresh 5s, same visual style as web app) |
| `GET /api/status` | probes, ~2s timeout each: lb, web1, web2 (`/healthz` direct), mysql (`SELECT 1`), redis (PING), queue depths (LLEN both), worker heartbeats (age of `heartbeat:worker-*`, stale > 20s) → `{"ok":bool,"generated_at":...,"services":[{"name","ok","latency_ms","detail"}]}` |
| `GET /api/stats` | `{"users":n,"forms":n,"submissions_total":n,"submissions_last_hour":n,"pdf_jobs":{"pending":n,"done":n,"failed":n},"top_forms":[{"title","count"}]}` |

## Nginx (config/nginx.conf.template)

- Runs unprivileged: `pid`/temp paths under `{{ROOT}}/storage/run/`, logs under
  `{{ROOT}}/storage/logs/`, inline minimal `types {}` block (no system mime.types).
- `upstream web_backend { least_conn; server 127.0.0.1:{{WEB1_PORT}} max_fails=3 fail_timeout=5s; server 127.0.0.1:{{WEB2_PORT}} max_fails=3 fail_timeout=5s; }`
- `location /status` and `location /api/` → `http://127.0.0.1:{{STATUS_PORT}}`
- `location /assets/` → served directly from `{{ROOT}}/apps/web/public`
- `location /` → `proxy_pass http://web_backend;` with `X-Forwarded-For`, `X-Request-Id ($request_id)`
- `log_format sre '... $status rt=$request_time upstream=$upstream_addr urt=$upstream_response_time rid=$request_id'`

## Scripts

`scripts/common.sh`: colors, `ok/warn/err/ask_yn` helpers, OS + package-manager
detection (brew/apt), `have <cmd>`, service-running probes. macOS bash 3.2
compatible — **no associative arrays, no `mapfile`**.

`setup.sh` (one-time, interactive): check/install php+extensions, node>=18,
mysql, redis, nginx (offer install per missing dep, never install silently);
copy `.env.example`→`.env` (prompt to keep defaults); ensure MySQL running;
create DB + app user (prompts for admin creds, defaults root/empty; on auth
failure with an apt-installed MySQL/MariaDB retry via `sudo mysql` because of
`auth_socket`); create the app user for BOTH `'localhost'` and `'127.0.0.1'`
hosts; apply schema.sql + seed.sql; `npm install` in apps/status; create
storage dirs; print demo credentials and `bash launch.sh` next-step.

`launch.sh [start|stop|restart|status|logs <name>]` (default start): re-check
each dependency interactively (missing binary → offer install; redis/mysql not
running → offer to start, brew services or direct daemon); verify DB exists
(else point at setup.sh); `mkdir -p storage/run/nginx_tmp storage/logs` etc.
first; render the nginx conf with plain sed-to-stdout (NEVER `sed -i`, it is
not portable):
`sed -e "s|{{ROOT}}|$ROOT|g" -e "s|{{LB_PORT}}|$LB_PORT|g" ... config/nginx.conf.template > storage/run/nginx.conf`.
Start web1/web2 with `PHP_CLI_SERVER_WORKERS=4` exported (php -S is otherwise
single-threaded and health probes + traffic would flap the upstream), e.g.
`INSTANCE_ID=web1 PHP_CLI_SERVER_WORKERS=4 nohup php -S 127.0.0.1:$WEB1_PORT -t apps/web/public apps/web/public/index.php ...`.
PID files in `storage/run/<name>.pid`, logs in `storage/logs/<name>.log`.
Nginx lifecycle: ALWAYS pass `-c "$ROOT/storage/run/nginx.conf"` to every
nginx invocation (start, `-t`, `-s stop`); nginx daemonizes itself, so its PID
comes from `storage/run/nginx.pid` (written per the conf), never from `$!`.
Readiness wait-loop targets exactly: `:WEB1_PORT/healthz`, `:WEB2_PORT/healthz`,
`:STATUS_PORT/healthz`, `:LB_PORT/healthz`; worker readiness = PID alive plus
`heartbeat:worker-*` key present in Redis (give them a few seconds). Print a
✓/✗ status table with URLs. `stop` kills PIDs gracefully (TERM, wait, KILL).
Idempotent: `start` skips healthy running services.

Demo login (seeded): `demo@reliableform.dev` / `demo1234`.

## Conventions (all builders)

- PHP: `declare(strict_types=1);` everywhere; PHP 8.2+; NO composer, NO
  external packages; prepared statements only; `e()` on all HTML output;
  `Csrf::validate()` on every POST.
- Node: NO dependencies beyond `mysql2`; no build step.
- UI: clean & very readable. Single CSS file, system font stack, white cards on
  `#f6f7fb` background, accent `#f97316` (orange), max-width 1040px container,
  visible focus states. No CSS/JS frameworks. The footer of every web page
  shows `served by <instance_id>` (LB demo).
- Every HTTP service exposes `/healthz` (workers are CLI loops — their
  liveness signal is the heartbeat key, not HTTP); every long-running loop
  heartbeats; every request/job emits statsd metrics (the hook for our SLI
  library).
- Errors: log to `storage/logs/`, never expose stack traces to users.

## Observability (iteration 1)

The playground exists to host the SLI stack and measure its overhead, so every
observability layer is independently toggleable from `.env`:

| layer | knob | cost when off |
|---|---|---|
| statsd metrics (UDP) | always on (fire-and-forget) | — |
| structured app log | `APPLOG_ENABLED=0` | zero (guard short-circuits) |
| OTel traces | unset `OTEL_EXPORTER_OTLP_ENDPOINT` | zero (no buffering, no spool) |

### Trace context (lib/Trace.php — implemented)

Web requests call `Trace::start($_SERVER['HTTP_TRACEPARENT'] ?? null)` first
thing; workers call `Trace::startFromJob($job)` per job (both fall back to a
fresh trace). `Queue::push()` embeds the current context in every job. Every
web response carries `X-Trace-Id: <trace_id>`. Outbound HTTP (webhooks, iter 2)
sends `traceparent: <Trace::traceparent()>`. Nginx's `X-Request-Id` (already
forwarded) is recorded as span attribute + log field `request_id` so LB access
log lines join traces.

### Spans (lib/Otel.php + apps/workers/otel_shipper.php — implemented)

NO direct export from request/job processes. `Otel::span(...)` buffers;
`Otel::flush()` appends one JSON line `{"service","instance","spans":[OTLP
span objects]}` to `storage/run/otel-spool/<instance>.jsonl`. The `otel`
sidecar service ships spool files to `$OTEL_EXPORTER_OTLP_ENDPOINT/v1/traces`
every ~2s (rename-handoff, retry, drop >120s with `otel.spans_dropped`).
Web: ONE SERVER span per request, flushed from a shutdown function —
name `<METHOD> <route-pattern>` (e.g. `GET /f/{public_id}`), attributes
`http.request.method`, `http.route`, `http.response.status_code`,
`app.request_id`, `app.user_id` (when logged in), error=status>=500.
Workers: ONE CONSUMER span per job — name `<queue> process`, attributes
`messaging.destination.name`, `app.submission_id`, `app.attempt`,
`app.outcome` (done|retried|dead|failed), error on failure — flushed at the
end of EVERY job iteration (worker processes never exit; the shutdown hook
alone would never fire).

### Structured log (lib/AppLog.php — implemented)

`AppLog::event($event, $fields)` → one JSON line in
`storage/logs/app-<instance>.jsonl` with automatic `ts`/`instance`/`event`/
`trace_id`. Web request event: `event=http_request`, fields `method`, `route`,
`status`, `dur_ms`, `request_id`, `user_id?`. Worker job event: `event=job`,
fields `queue`, `submission_id`, `attempt`, `outcome`, `dur_ms`. String fields
are truncated to 512 chars (atomic-append invariant: lines ≪ 8KB).

### Submission rate limit

`POST /f/{public_id}`: before validation, INCR
`ratelimit:submit:<public_id>:<request_ip()>` (EXPIRE 60 on first); over
`RATE_LIMIT_SUBMIT_PER_MIN` ⇒ friendly 429 page + `web.submission.ratelimited`
counter. Redis errors ⇒ fail open. 0 disables (loadgen A/B runs use that or
rotate synthetic `X-Forwarded-For` per virtual user).

### Load generator (scripts/loadgen.sh)

Traffic mix against the LB (landing, public form GET, real POST submissions
with CSRF, healthz, /api/status). Client-side fork overhead would dwarf the
instrumentation delta being measured, so loadgen reports **server-side**
latency: it marks the run window, then computes p50/p90/p99 from the
`rt=` field of `storage/logs/nginx-access.log` for that window, plus its own
request/error counts. Each virtual user sends a distinct
`X-Forwarded-For: 10.77.<vu>.<n>` so the submit rate limit spreads.
Usage: `bash scripts/loadgen.sh [-d seconds] [-c concurrency] [--submits-only|--reads-only]`.

## Product surface (iteration 2)

### REST API v1 (the JotForm-style public API)

Lives in the web app under path prefix `/v1/` (NOT `/api/` — nginx routes
`/api/` to the status service). The router dispatches `^/v1/` BEFORE the CSRF
guard into `apps/web/src/api/` and `define('RF_API', true)` (the bootstrap
exception handler then emits JSON, never HTML). The v1 path must NEVER call
`Session::start()` (no Csrf, no `Auth::user()` — no cookies minted for API
traffic). Auth: `Authorization: Bearer <key>` or `X-Api-Key: <key>` →
`users.api_key` lookup (40 hex chars; seeded demo key
`deadbeefdeadbeefdeadbeefdeadbeefdeadbeef`). Per-key rate limit:
`ratelimit:api:<key>` INCR + EXPIRE 60, limit `API_RATE_LIMIT_PER_MIN`
(default 120, 0 off, Redis errors fail open) ⇒ 429 + `Retry-After: 30`.

Response envelope: success `{"ok":true,...}`; errors
`{"ok":false,"error":{"code":"<machine_code>","message":"...","trace_id":"..."}}`
with codes: `unauthorized` 401, `not_found` 404, `method_not_allowed` 405,
`unsupported_media_type` 415, `validation_failed` 422 (plus
`"fields":{"<field_id>":"<msg>"}`), `rate_limited` 429, `internal_error` 500.

| endpoint | behavior |
|---|---|
| `GET /v1/me` | `{"ok":true,"user":{id,email,name,created_at}}` |
| `GET /v1/forms` | my forms: `[{id,public_id,title,is_published,submissions,webhook_url,created_at,updated_at}]` |
| `GET /v1/forms/{public_id}` | full definition incl. `fields` array (owner only; 404 otherwise) |
| `GET /v1/forms/{public_id}/submissions?limit=&offset=` | `{"ok":true,"total":n,"submissions":[{id,created_at,data}]}` — limit ≤100 default 20, offset ≥0 |
| `POST /v1/forms/{public_id}/submissions` | body `{"answers":{"<field_id>":value}}`, Content-Type must be application/json (else 415); identical validation + transaction + stats/queue side effects as the web submit path (incl. webhook_deliveries row when the form has a webhook); file fields per the widget section; 201 `{"ok":true,"submission":{"id":n}}` |

Targets the OWNER's forms only (the key authenticates the owner). Metrics:
`api.request`, `api.request_ms`, `api.status.<code>`; the standard SERVER
span/AppLog instrumentation applies with route patterns like
`POST /v1/forms/{public_id}/submissions`.

Web UI: the dashboard shows an "API key" card (key, copy button, one curl
example) and `POST /account/api-key` (CSRF) regenerates it.

### Webhooks

`forms.webhook_url` (nullable, set in the builder's "Integrations" section;
server validates `^https?://`, ≤500 chars). When set, the submit transaction
also inserts a `pending` `webhook_deliveries` row and pushes a
`{"type":"webhook","submission_id":N,"attempt":0,...}` job to `queue:webhook`
after commit. `worker-webhook` follows the standard worker loop contract plus:

- Load delivery row + submission + form; missing row ⇒ dead-letter (never
  INSERT); status already `delivered` ⇒ no-op (idempotency).
- Mark `delivering`, then POST JSON
  `{"event":"submission.created","form":{"public_id","title"},"submission":{"id","created_at","answers":[{"id","label","type","value"}]},"delivery":{"id","attempt"}}`
  with headers `Content-Type: application/json`,
  `User-Agent: ReliableForm-Webhook/1.0`, `X-ReliableForm-Delivery: <id>`,
  `traceparent: <Trace::traceparent()>` (trace continues into the receiver).
- curl ext: CONNECTTIMEOUT_MS 2000, TIMEOUT_MS 5000, FOLLOWLOCATION false.
  2xx ⇒ `delivered` (+response_code, duration_ms, attempts). Non-2xx/timeout ⇒
  standard retry contract (3 attempts, `sleep_with_heartbeat` backoff, then
  `failed` + dead-letter). Stored `error` truncated to 500 chars. heartbeat
  immediately after curl returns. Log a warning when the webhook host:port is
  the stack's own LB/web ports (self-loop drill hazard).
- One extra CLIENT span per attempt: name `webhook deliver`, fresh spanId,
  parentSpanId = the job's CONSUMER span id, attrs `url.full` (scheme+host+port
  only, no path — keep cardinality sane), `http.response.status_code`,
  `app.attempt`, `app.delivery_id`; error on failure.
- Metrics: `worker.webhook.delivered`/`failed`/`job_ms` + the standard start.

`tools/webhook-sink.php` (run: `php -S 127.0.0.1:9444 tools/webhook-sink.php`):
logs one line per delivery to stdout (delivery id, submission id, status
served, attempt); responds `{"ok":true}` 200 by default; `?fail=1` ⇒ always
500; `?fail_first=N` ⇒ 500 for the first N attempts of each delivery id
(state file in /tmp keyed by X-ReliableForm-Delivery), then 200 — this makes
"retry then deliver" deterministically testable.

The status service (`/api/status`) additionally probes `queue:webhook` depth
and the `worker-webhook` heartbeat. `launch.sh` runs the worker via the
`WORKER_SERVICES` list (one-line addition; everything derives from it).

### CSV export

`GET /forms/{id}/export.csv` (session auth + ownership): streamed
`text/csv; charset=utf-8`, `Content-Disposition: attachment;
filename="<public_id>-submissions.csv"`. Columns: `submission_id`,
`submitted_at_utc`, then one column per field (label as header, in field
order). Checkbox arrays joined `"; "`. RFC-4180 quoting (wrap in quotes,
double inner quotes). Prefix any cell starting with `= + - @` with a single
quote (spreadsheet formula-injection guard). Metric: `web.export`.

### Schema migrations

`db/schema.sql` stays the complete CURRENT schema (fresh installs run only
it). Changes to existing databases ship as `db/migrations/NNN_name.sql`,
applied in lexicographic order and recorded in `schema_migrations`. setup.sh:
fresh database (setup just created it) ⇒ apply schema.sql + seed.sql, then
BASELINE-insert every migration id WITHOUT executing the files; existing
database ⇒ ensure `schema_migrations` exists, apply + record each pending
migration. Never run a migration twice; stop with a clear error if one fails.
