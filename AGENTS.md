

This file provides guidance to Agents when working with code in this repository.

## What this is

ReliableForm is a JotForm-style form builder whose **real purpose is the production topology**, not the product. It exists as an SRE lab bench: a multi-service stack (nginx load balancer → replicated PHP web tier → Node status service → MySQL/Redis → PHP CLI workers consuming a job queue) instrumented so SLIs, health checks, queues, load balancing, and failure modes can be defined and broken on a stack we fully control. Keep things small and readable; this is a lab, not a shipping product.

**`docs/ARCHITECTURE.md` is the integration contract** — ports, Redis key conventions, queue job protocol, the full route table, DB schema, JSON envelope shapes, the worker-loop contract, and the kernel API. Read it before implementing anything that crosses a service boundary, and follow it exactly. This file is the orientation layer on top of it.

## Commands

```bash
bash setup.sh                 # one-time: interactive dep check/install, create+seed DB. --yes for CI
bash launch.sh                # start the whole stack, print a ✓/✗ health table. --yes for CI
bash launch.sh stop|restart|status
bash launch.sh logs <svc>     # tail a service log
bash launch.sh kill <svc>     # kill one service (failure drills); `start` brings it back
```

Service names (the `<svc>` argument): `web1 web2 status worker-pdf worker-email worker-webhook otel nginx gearmand`.

### Tests

```bash
bash tests/run-unit.sh                              # PHP unit suite (no MySQL; Redis cases self-skip)
INSTANCE_ID=unittest php tests/unit/test_validation.php   # a single PHP unit file
(cd apps/status && npm test)                        # Node unit suite (node:test, zero deps)
(cd apps/status && node --test test/env.test.js)    # a single Node test file
(cd frontend && pnpm -r test)                       # frontend lib tests (vitest)
(cd frontend && pnpm -r typecheck)                  # frontend TypeScript check
bash tests/e2e.sh [--full] [--keep]                 # e2e regression gate — needs a RUNNING stack
```

`tests/e2e.sh` is THE regression gate; it requires a healthy running stack and exits **2** (not 1) when the environment isn't ready vs **1** for an actual regression — gate CI on that distinction. `--full` adds an API rate-limit check that **locks the demo API key for ~60s**, so don't use it while anything else shares the key. See `docs/TESTING.md`.

### Frontend build (optional — the PHP app runs fine without it)

```bash
cd frontend && pnpm install && pnpm --filter @rf/telemetry-entry build  # or `pnpm build` for all apps
bash launch.sh restart        # re-render nginx so /build/ is served
```

Browser telemetry is injected only when `frontend/build/asset-manifest.json` exists; absent it, the PHP pages skip the `<script>` and there is zero cost.

### Load generator

```bash
bash scripts/loadgen.sh -d 60 -c 4   # realistic traffic mix; reports SERVER-side p50/p90/p99 from the nginx log
```

There is **no linter or formatter** and no composer/PHP package manager — conventions below are enforced by reading, not tooling.

## Architecture you can't see from one file

- **Driver facades resolved at launch, never mid-run.** Three runtime shapes are env-selected and `launch.sh` resolves the ambiguous ones *once* and exports the decision to every process so services can't disagree:
  - `WEB_RUNTIME=auto|fpm|cli` — PHP-FPM pools (production parity, opcache on) vs `php -S`.
  - `QUEUE_DRIVER=auto|gearman|redis` — gearmand + `lib/GearmanLite.php` (pure-PHP binary protocol) vs Redis lists. `lib/Queue.php` is a facade with **unchanged public API and metric names** across drivers; `lib/WorkerLoop.php` hides the consume split. Dead letters always go to the Redis `queue:dead` list regardless of driver.
  - `SESSION_DRIVER=mysql|redis` — MySQL `sessions` table (default, production parity; sessions survive a Redis outage) vs Redis.
- **The `lib/` kernel is finished and lint-clean — read it, code against it, do not modify or redefine it.** Every PHP entry point starts with `require .../lib/bootstrap.php`, which defines `RF_ROOT`, loads `.env` via `Config`, registers the autoloader, sets UTC + error handlers. The kernel already handles the subtle stuff (BLPOP socket-timeout pitfall, session fixation defense, `X-Forwarded-For` in `request_ip()`, friendly 500 page). The full callable API is the "Kernel API" section of `docs/ARCHITECTURE.md`.
- **Routing is one front controller.** `apps/web/public/index.php` is both the router and the `php -S` router script (returns `false` for real files under `public/`). Routes are an if/else ladder of `preg_match` patterns; each sets a `$routePattern` token (e.g. `/f/{public_id}`) used verbatim in spans and logs. A `register_shutdown_function` emits the per-request metric/span/AppLog for every path.
- **`/v1/*` is a separate world.** Matched first, before the CSRF guard, dispatched into `apps/web/src/api/` with `RF_API` defined (bootstrap then emits the JSON envelope instead of HTML). It is **sessionless by contract**: never call `Session::start()`, `Csrf`, or `Auth::user()` on that path — no cookie may be minted for API traffic. It uses the `api.*` metric family; web traffic uses `web.*`; never both for one request. Note `/api/*` is the *Node status service* (nginx routes it there), distinct from `/v1/*`.
- **Web app pages vs views.** `apps/web/src/pages/*.php` are route handlers (one per route); `apps/web/src/views/*.php` are rendered templates via `render()`. Public forms stay server-rendered PHP; the `frontend/` workspace builds browser telemetry today and is the foundation for future React SPAs (builder/dashboard) hosted by `lib/FrontendLoader.php` — not built yet.
- **Workers are CLI loops with a strict contract** (BLPOP/grab → idempotent process → retry with backoff → dead-letter after 3 attempts → heartbeat every loop → graceful SIGTERM). Their liveness signal is the `heartbeat:<instance>` Redis key, not HTTP. Web app owns the `pdf_jobs`/`emails`/`webhook_deliveries` rows (created in the submission transaction); workers only UPDATE them. The exact contract is in `docs/ARCHITECTURE.md` — match it.
- **Row ownership rule:** the submission insert + the `pending` job rows happen in **one transaction**; queue jobs are pushed only *after* commit, and a Redis failure at push time must not 500 the user (flash a warning).

## Conventions (enforced by review, not tooling)

- **PHP:** `declare(strict_types=1);` everywhere, PHP 8.2+, no composer/frameworks, prepared statements only, `e()` on all HTML output, `Csrf::validate()` at the top of every POST handler.
- **Node status service:** no dependency beyond `mysql2`, no build step. (The separate `frontend/` pnpm+Rspack workspace is the only place with a build.)
- **Shell scripts must be macOS bash 3.2 compatible** — no associative arrays, no `mapfile`. Render config templates with **sed-to-stdout (`sed ... > out`), never `sed -i`** (not portable). Helpers live in `scripts/common.sh`.
- **Time is always UTC** for stats buckets and timestamps (`gmdate` in PHP, `getUTC*` in Node) — never local time.
- **Observability is toggleable and zero-cost when off** by design (`APPLOG_ENABLED`, `OTEL_EXPORTER_OTLP_ENDPOINT` unset, no frontend manifest, `CLIENTLOG_ENABLED`). Don't add always-on instrumentation overhead. Metric/log/span catalog is `docs/METRICS.md`.

## Docs map

`docs/ARCHITECTURE.md` (the contract) · `docs/API.md` (REST v1 + webhooks + CSV) · `docs/RUNBOOK.md` (drills, queue/webhook surgery, migrations) · `docs/SRE-GUIDE.md` (telemetry layers, SLI candidates, overhead A/B) · `docs/METRICS.md` (every metric/log/span) · `docs/TESTING.md` (the suites).

Demo login: `demo@reliableform.dev` / `demo1234`. Seeded API key: `deadbeefdeadbeefdeadbeefdeadbeefdeadbeef`. Schema changes ship as `db/migrations/NNN_name.sql` (applied by `setup.sh`); `db/schema.sql` stays the complete current schema for fresh installs.
