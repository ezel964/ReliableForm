# ReliableForm Implementation And Architecture Summary

This file explains what ReliableForm is, what has been implemented, and how the moving pieces connect.

It is written for someone who does not already know Nginx, PDF workers, queues, or production-style web architecture.

## What ReliableForm Is

ReliableForm is a small JotForm-style form builder.

Users can:

- Register and log in.
- Create forms with fields like text, email, number, select, checkbox, rating, date, and file upload.
- Share a public form link.
- Receive submissions.
- View submissions in a dashboard.
- Download generated PDFs for submissions.
- Export submissions as CSV.
- Configure webhooks.
- Use a REST API.
- Configure form settings like publishing, submission limits, thank-you messages, autoresponders, conditional logic, and embeds.

But the main goal is not just forms. The project is also an SRE playground.

That means it is intentionally built like a miniature production system:

- There is a load balancer.
- There are two web app instances.
- There are background workers.
- There is a database.
- There is Redis for cache, rate limits, heartbeats, and fallback queues.
- There may be Gearman for production-style background jobs.
- There is a status dashboard.
- There are metrics, logs, traces, health checks, and failure drills.

In simple words: this is a form app built to teach and test how real services connect.

## The Big Picture

When you open the app, you do not talk directly to PHP.

You talk to Nginx.

Nginx then decides where the request should go.

```text
Browser
  |
  v
Nginx on port 8080
  |
  |-- /status and /api/*  --> Node status service on port 9301
  |
  |-- /assets/*           --> static files from disk (apps/web/public/assets)
  |
  |-- /build/*            --> hashed frontend bundles from frontend/build/
  |
  |-- everything else     --> PHP web app, web1 or web2
```

The PHP web app talks to MySQL and Redis.

The workers also talk to MySQL and Redis, and optionally Gearman.

```text
Browser
  |
  v
Nginx
  |
  |-------------------------|
  |                         |
  v                         v
PHP web1 / web2          Node status service
  |
  |-------------------------|
  |                         |
  v                         v
MySQL                   Redis / Gearman
  |
  v
Workers: PDF, email, webhook, OpenTelemetry shipper
```

## What Nginx Does

Nginx is the front door.

Think of Nginx as a receptionist.

The browser only knows one public address:

```text
http://localhost:8080
```

Behind that one address, there are multiple services. The browser does not need to know them.

Nginx receives the request and decides:

- Is this a static asset like CSS or JavaScript under `/assets/`? Serve it directly from `apps/web/public/assets`.
- Is this a hashed frontend bundle under `/build/`? Serve it directly from `frontend/build/` (long cache).
- Is this `/status` or `/api/...`? Send it to the Node status service.
- Is this a normal app page like `/dashboard`, `/login`, or `/f/abc123`? Send it to the PHP web app.

This is called reverse proxying.

It means Nginx accepts a request on behalf of another service, forwards it internally, gets the response, and sends it back to the browser.

## Why There Are Two Web Apps

The app runs two PHP web instances:

- `web1`
- `web2`

They run the same code.

Nginx balances traffic between them.

This lets the project simulate a real production setup where multiple app servers run behind one load balancer.

If `web1` dies, Nginx can keep sending traffic to `web2`.

This is why pages show which instance served them. It makes load balancing visible.

## How Nginx Balances Web Traffic

Nginx defines an upstream called `web_backend`.

That upstream contains both web servers:

```text
web1 -> 127.0.0.1:9001
web2 -> 127.0.0.1:9002
```

Nginx uses `least_conn`.

That means it tries to send the next request to the web instance with the fewest active connections.

If one instance fails repeatedly, Nginx temporarily avoids it.

## PHP Runtime: FPM Or CLI

The project supports two ways to run PHP web instances.

### PHP-FPM Mode

This is the more production-like mode.

PHP-FPM is a long-running PHP process manager. Nginx talks to it using FastCGI.

In this mode:

```text
Nginx -> FastCGI -> PHP-FPM pool web1
Nginx -> FastCGI -> PHP-FPM pool web2
```

Each pool is separate. Killing `web1` only kills that pool, not the whole app.

### PHP Built-In Server Mode

This is the fallback/dev mode.

PHP runs its built-in HTTP server:

```text
php -S 127.0.0.1:9001
php -S 127.0.0.1:9002
```

In this mode:

```text
Nginx -> HTTP proxy -> PHP built-in server
```

The same app code works in both modes.

`launch.sh` decides which mode to use based on `WEB_RUNTIME`.

## What `launch.sh` Does

`launch.sh` is the local process manager.

This project does not use Docker. It starts real local processes directly.

When you run:

```bash
bash launch.sh
```

it starts the stack:

- `web1`
- `web2`
- `status`
- `worker-pdf`
- `worker-email`
- `worker-webhook`
- `otel`, if tracing export is enabled
- `nginx`
- `gearmand`, if Gearman is the selected queue driver

It also:

- Reads configuration from `.env`.
- Chooses PHP-FPM or PHP built-in server mode.
- Chooses Gearman or Redis queues.
- Renders Nginx config files into `storage/run`.
- Creates PID files in `storage/run`.
- Writes logs to `storage/logs`.
- Checks health endpoints.
- Prints a status table.

## What The PHP Web App Does

The PHP web app is the main product.

Its front controller is:

```text
apps/web/public/index.php
```

All normal web requests go through this file.

It handles routes like:

- `/`
- `/login`
- `/register`
- `/dashboard`
- `/forms/new`
- `/forms/{id}/edit`
- `/forms/{id}/settings`
- `/forms/{id}/submissions`
- `/submissions/{id}`
- `/submissions/{id}/pdf`
- `/f/{public_id}`
- `/f/{public_id}/thanks`
- `/f/{public_id}/embed`
- `/healthz`

It also handles REST API routes under:

```text
/v1/...
```

Important detail:

`/api/...` is not the PHP API.

Nginx sends `/api/...` to the Node status service.

The product REST API uses `/v1/...`.

## Frontend Architecture (Hybrid / Strangler)

ReliableForm follows a JotForm-style hybrid frontend plan:

- **Public forms stay PHP server-rendered.** Visitors fill forms via normal HTML pages.
- **React SPAs are planned** for the authenticated dashboard (Plan 2) and form builder (Plan 3).
- **Plan 1 (done)** delivered the shared frontend foundation plus browser SRE telemetry on existing PHP pages — no SPA routes are wired yet.

### What Plan 1 Built

**Backend (PHP):**

- `POST /v1/rum` and `POST /v1/clientlog` — sessionless, unauthenticated telemetry beacons (204, no JSON envelope). See `apps/web/src/api/rum.php` and `clientlog.php`; routed in `dispatch.php` without `api_authenticate()`.
- `lib/FrontendLoader.php` — renders a React host shell, injecting `window.__rfrouter` (ENV, asset path, revision, trace id, CSRF, base path, active page) and revision-hashed scripts from `frontend/build/asset-manifest.json`. Mirrors JotForm's FrontendLoader/router-bridge pattern. **Not yet wired to any route.**
- `apps/web/src/views/spa_shell.php` — minimal `<div id="root">` host page for future SPAs.
- `apps/web/src/views/layout.php` — stable `$rfPage` token, reads `asset-manifest.json`, sets `<body data-rf-page>`, injects the telemetry bundle when built. RUM, trace propagation, and JS error reporting already run on all existing PHP pages.

**Frontend workspace (`frontend/`):**

- pnpm workspace with Rspack + TypeScript (mirrors JotForm's bundler/toolchain).
- Shared libs: `@rf/router-bridge`, `@rf/request-layer`, `@rf/telemetry`.
- `@rf/telemetry-entry` builds the injectable bundle → `frontend/build/telemetry.<hash>.js`; `frontend/scripts/write-manifest.mjs` writes `frontend/build/asset-manifest.json`.
- Build: `cd frontend && pnpm install && pnpm --filter @rf/telemetry-entry build` (requires pnpm).

**Nginx:** new `location /build/` serves hashed bundles from `frontend/` with long-lived immutable cache. Re-render via `bash launch.sh restart` after template changes.

**Configuration (`.env`):** `RUM_SAMPLE_RATE` (default `1.0`), `CLIENTLOG_ENABLED` (default `1`), `CLIENTLOG_RATE_PER_MIN` (default `120`).

**Telemetry:** browser web-vitals → `/v1/rum`; `window.onerror` / unhandled rejections → `/v1/clientlog`; same-origin fetch/XHR gets `traceparent` so browser work joins backend traces. StatsD `web.client.*` family and AppLog `client_error` events — full catalog in `docs/METRICS.md` and SLO targets in `docs/SRE-GUIDE.md`.

**Tests:** `tests/unit/test_rum.php`, `test_clientlog.php`, `test_frontend_loader.php` (`bash tests/run-unit.sh`); frontend vitest (`cd frontend && pnpm -r test`).

### What Is Still Planned

| plan | scope | status |
|---|---|---|
| Plan 2 | React dashboard SPA (uses `FrontendLoader` + `spa_shell.php`) | not started |
| Plan 3 | React form builder SPA | not started |
| future | `/v1` CRUD expansion + cookie-session auth for the SPAs | not started |

Design docs: `docs/superpowers/specs/2026-06-16-reliableform-react-stack-design.md`, `docs/superpowers/plans/2026-06-16-frontend-foundation.md`.

## MySQL: The Main Database

MySQL stores the important long-term data.

The main tables are:

- `users`
- `forms`
- `submissions`
- `pdf_jobs`
- `emails`
- `webhook_deliveries`
- `sessions`
- `schema_migrations`

In plain language:

- `users` stores accounts.
- `forms` stores form definitions.
- `submissions` stores submitted answers.
- `pdf_jobs` tracks whether a PDF has been generated.
- `emails` tracks notification/autoresponder email files.
- `webhook_deliveries` tracks webhook attempts.
- `sessions` stores logged-in user sessions when `SESSION_DRIVER=mysql`.
- `schema_migrations` tracks which migrations already ran.

## Redis: Fast Shared State

Redis is used for fast temporary/shared state.

Depending on configuration, Redis can store:

- Session data, if `SESSION_DRIVER=redis`.
- Form cache.
- Rate limit counters.
- Submission counters.
- Worker heartbeats.
- Redis queue lists.
- Dead-letter jobs.
- Gearman fallback queue jobs.

Redis is not the source of truth for forms and submissions. MySQL is.

Redis is used for things that are fast, temporary, or operational.

## Gearman: Production-Like Job Queue

The project can use Gearman for background jobs.

Gearman is a job server.

In simple terms:

- The web app creates a job.
- Gearman stores the job.
- A worker asks Gearman for work.
- Gearman gives the job to the worker.
- The worker marks it complete.

ReliableForm supports two queue drivers:

- `gearman`
- `redis`

The setting is controlled by:

```text
QUEUE_DRIVER=auto|gearman|redis
```

When `QUEUE_DRIVER=auto`, `launch.sh` picks Gearman if `gearmand` exists. Otherwise it falls back to Redis.

## Redis Queues Vs Gearman Queues

Redis queue mode is simpler.

The web app pushes jobs into Redis lists:

```text
queue:pdf
queue:email
queue:webhook
```

Workers block and wait for jobs using Redis.

Gearman mode is more production-like.

The web app submits jobs to Gearman functions:

```text
queue:pdf     -> rf_pdf
queue:email   -> rf_email
queue:webhook -> rf_webhook
```

Workers register for those functions and receive jobs from Gearman.

Even in Gearman mode, Redis still matters:

- `queue:dead` stays in Redis.
- If Gearman is down, jobs are parked in Redis fallback lists.
- Workers drain those Redis fallback lists until Gearman comes back.

This means a temporary Gearman outage should not lose submissions.

## What Workers Are

Workers are long-running PHP command-line scripts.

They are not web pages.

They do not receive browser requests.

They sit in a loop and wait for background jobs.

ReliableForm has these workers:

- `worker-pdf`
- `worker-email`
- `worker-webhook`
- `otel`

The first three process product jobs.

The `otel` worker ships tracing data if tracing is enabled.

## Why Workers Exist

Some work should not happen while the user is waiting.

For example, when someone submits a form:

- Save the submission immediately.
- Show the thank-you page quickly.
- Generate the PDF later.
- Write the email file later.
- Deliver webhook later.

That is why workers exist.

The web app handles the user-facing request. Workers handle slower background tasks.

## The Submission Flow

Here is what happens when someone submits a public form.

### 1. Browser Sends A Form Submission

The browser posts to:

```text
POST /f/{public_id}
```

The request first hits Nginx.

Nginx forwards it to either `web1` or `web2`.

### 2. PHP Validates The Submission

The PHP app checks:

- Does the form exist?
- Is the form published?
- Is the submission limit reached?
- Are required fields filled?
- Are email fields valid?
- Are number fields valid?
- Are selected options allowed?
- Are hidden conditional fields handled correctly?
- Is the submission rate limit exceeded?

If validation fails, the user sees an error.

### 3. PHP Writes To MySQL

If validation passes, PHP opens a database transaction.

Inside that transaction it creates:

- A `submissions` row.
- A `pdf_jobs` row with status `pending`.
- One or more `emails` rows with status `pending`.
- A `webhook_deliveries` row if the form has a webhook URL.

This is important.

The app records the durable facts in MySQL first.

Only after the database commit does it push background jobs.

That prevents workers from seeing a job before the database row exists.

### 4. PHP Pushes Background Jobs

After the transaction commits, the app pushes jobs:

- PDF job
- Email notification job
- Autoresponder email job, if enabled and an email answer exists
- Webhook job, if configured

These jobs go through the queue system.

That may mean Gearman or Redis, depending on `QUEUE_DRIVER`.

### 5. User Gets Redirected

The user is redirected to:

```text
/f/{public_id}/thanks
```

The user does not wait for PDF generation or webhook delivery.

### 6. Workers Process The Jobs

The PDF worker generates a PDF and writes it to:

```text
storage/pdfs/
```

The email worker writes `.eml` files to:

```text
storage/mail/
```

It does not send real email.

The webhook worker sends a JSON POST to the configured webhook URL.

If a worker fails, it retries up to 3 attempts. After that, the job becomes failed and may be written to the dead-letter queue.

## PDF Worker Explained Simply

The PDF worker is just a PHP script that waits for PDF jobs.

It does not magically receive submissions.

The connection is:

```text
form submit
  -> PHP web app inserts submission and pdf_jobs row
  -> PHP web app pushes a PDF queue job
  -> worker-pdf picks up the job
  -> worker-pdf reads the submission from MySQL
  -> worker-pdf creates a PDF file
  -> worker-pdf updates pdf_jobs.status to done
  -> submission page can now offer the PDF download
```

If the PDF is not ready yet, the download page shows a friendly waiting page.

## Email Worker Explained Simply

The email worker writes email files.

It does not send real email over the internet.

The flow is:

```text
form submit
  -> emails row created
  -> email queue job pushed
  -> worker-email picks it up
  -> worker-email writes a .eml file
  -> emails.status becomes sent
```

The email files live under:

```text
storage/mail/
```

## Webhook Worker Explained Simply

A webhook means:

"When a form is submitted, send the submission data to another URL."

The flow is:

```text
form submit
  -> webhook_deliveries row created
  -> webhook queue job pushed
  -> worker-webhook picks it up
  -> worker-webhook POSTs JSON to the configured URL
  -> row becomes delivered or failed
```

The worker retries failed deliveries.

After too many failures, the job is considered failed and can be inspected later.

## Node Status Service

The Node service is not the main app.

It is the dashboard and health/analytics service.

It runs on:

```text
127.0.0.1:9301
```

Nginx exposes it through:

```text
http://localhost:8080/status
http://localhost:8080/api/status
http://localhost:8080/api/stats
```

It checks things like:

- Is Nginx reachable?
- Is `web1` healthy?
- Is `web2` healthy?
- Is MySQL healthy?
- Is Redis healthy?
- Is Gearman healthy, if enabled?
- How deep are the queues?
- Are workers heartbeating?
- How many users, forms, submissions, and jobs exist?

## Health Checks

A health check is a simple endpoint that answers:

"Is this service alive and able to do basic work?"

The PHP web app has:

```text
/healthz
```

Nginx also exposes special per-pool health URLs:

```text
/__pool/web1/healthz
/__pool/web2/healthz
```

These are useful because in PHP-FPM mode you cannot just HTTP-request port `9001` directly. Nginx must speak FastCGI to that pool.

Workers do not have HTTP endpoints.

Instead, workers write heartbeat keys to Redis:

```text
heartbeat:worker-pdf
heartbeat:worker-email
heartbeat:worker-webhook
```

If the heartbeat key expires, the status service treats that worker as down.

## Sessions

Sessions keep users logged in between requests.

The project supports:

- MySQL sessions
- Redis sessions

The default production-like mode is MySQL sessions:

```text
SESSION_DRIVER=mysql
```

This means login survives Redis outages better, because session data is in MySQL.

If configured as Redis sessions, session data goes into Redis keys.

## Caching And Rate Limits

Redis is used for small fast counters and caches.

Examples:

- Cache a public form for 60 seconds.
- Count submissions per minute for rate limiting.
- Count API requests per key.
- Count login attempts per IP.
- Count public form views.

If Redis has a problem, some features degrade. For example, rate limits may fail open so real users are not blocked just because Redis is down.

## REST API

The REST API lives under:

```text
/v1
```

Examples:

- `GET /v1/me`
- `GET /v1/forms`
- `GET /v1/forms/{public_id}`
- `GET /v1/forms/{public_id}/submissions`
- `POST /v1/forms/{public_id}/submissions`
- `POST /v1/rum` — browser Core Web Vitals beacon (unauthenticated; 204)
- `POST /v1/clientlog` — browser JS error beacon (unauthenticated; 204)

The CRUD endpoints use API keys.

They do not use browser sessions or CSRF.

The two telemetry beacons also skip API keys — they are fire-and-forget browser sinks.

This is intentional because API clients are not normal browser form sessions.

## Observability

Observability means:

"Can we understand what the system is doing from the outside?"

ReliableForm has several observability surfaces.

### Metrics

The app emits StatsD-style metrics like:

- request count
- request duration
- status code count
- job duration
- queue fallback count
- form view count
- browser client timings (`web.client.lcp|inp|cls|ttfb|fcp|fid`) and error counters (`web.client.error`, malformed/rate-limited beacons)

These are sent over UDP and do not block the app.

See `docs/METRICS.md` for the full catalog including frontend SRE metrics.

### Logs

Logs are written under:

```text
storage/logs/
```

Important logs include:

- Nginx access log
- Nginx error log
- web instance logs
- worker logs
- structured app logs
- AppLog `client_error` events from browser JS error beacons (`POST /v1/clientlog`)

Nginx logs include timing and upstream information, which helps answer:

- Which backend handled the request?
- How long did the request take?
- Was the upstream slow?
- What request ID was assigned?

### Traces

Traces connect a web request to work that happens later.

For example:

```text
submission request
  -> queue job
  -> PDF worker
  -> email worker
  -> webhook worker
```

The trace context is attached to queue jobs so workers can continue the same trace.

Spans are written to local spool files and shipped by the `otel` sidecar if OpenTelemetry export is enabled.

## Storage Directory

Runtime files go under:

```text
storage/
```

Important subdirectories:

- `storage/run` has PID files and rendered config.
- `storage/logs` has logs.
- `storage/pdfs` has generated PDF files.
- `storage/mail` has generated `.eml` files.
- `storage/uploads` has uploaded files.

These are runtime artifacts, not source code.

## What Was Implemented

The project includes the full local stack:

- PHP web application with form builder, auth, dashboard, public forms, submissions, settings, embeds, CSV export, and REST API.
- Frontend foundation workspace (`frontend/`) with injectable telemetry bundle on existing PHP pages; React dashboard/builder SPAs planned (Plans 2–3).
- Browser SRE: RUM beacons, JS error reporting, and trace propagation from the client (`POST /v1/rum`, `POST /v1/clientlog`).
- Two web instances behind Nginx.
- Nginx reverse proxy and load balancer.
- Node status and analytics service.
- MySQL schema, seed data, and migrations.
- Redis client and Redis-backed operational state.
- Optional Gearman queue driver with Redis fallback.
- PDF worker.
- Email worker.
- Webhook worker.
- OpenTelemetry shipper worker.
- Metrics, structured logs, trace context, health checks, and worker heartbeats.
- Setup and launch scripts.
- Unit, Node, and end-to-end test entry points.

## Most Important Request Paths

### Normal Page Request

```text
Browser
  -> Nginx :8080
  -> PHP web1 or web2
  -> MySQL / Redis as needed
  -> PHP returns HTML
  -> Nginx returns response to browser
```

### Status Page Request

```text
Browser
  -> Nginx :8080
  -> Node status service :9301
  -> probes web1, web2, MySQL, Redis, Gearman, queues, heartbeats
  -> returns dashboard or JSON
```

### Static Asset Request

```text
Browser
  -> Nginx :8080
  -> Nginx reads CSS/JS/image from apps/web/public/assets (path /assets/)
  -> returns file directly
```

PHP does not handle this request.

### Frontend Bundle Request

```text
Browser
  -> Nginx :8080
  -> Nginx reads hashed JS from frontend/build/ (path /build/)
  -> returns file directly with long cache headers
```

PHP does not handle this request. The telemetry bundle is referenced from
`frontend/build/asset-manifest.json` and injected by `layout.php`.

### Public Form Submission

```text
Browser
  -> Nginx
  -> PHP web app
  -> MySQL transaction creates submission and job rows
  -> Queue gets PDF/email/webhook jobs
  -> Browser gets thank-you page
  -> Workers process jobs in the background
```

## Why The Architecture Is Built This Way

The architecture is intentionally more complex than a simple PHP app.

A simple app could be:

```text
Browser -> PHP -> MySQL
```

ReliableForm is more like:

```text
Browser -> Nginx -> multiple PHP instances -> MySQL/Redis/Gearman -> workers
```

That extra structure exists so you can test real operational questions:

- What happens if one web server dies?
- What happens if Redis dies?
- What happens if Gearman dies?
- What happens if a worker dies?
- Do jobs get lost?
- Do queues grow?
- Do health checks notice?
- Do logs and traces show what happened?
- Can the app still serve users during partial failures?

## Beginner Mental Model

If you remember only one thing, remember this:

```text
Nginx is the front door.
PHP is the main app.
MySQL stores permanent data.
Redis stores fast temporary/shared data.
Gearman or Redis carries background jobs.
Workers do slow work later.
Node shows health and stats.
storage/ holds runtime files.
```

The browser talks to Nginx.

Nginx talks to the right internal service.

The web app writes durable records to MySQL.

Queues tell workers what to do.

Workers update MySQL and write files.

The status service watches the whole thing.

