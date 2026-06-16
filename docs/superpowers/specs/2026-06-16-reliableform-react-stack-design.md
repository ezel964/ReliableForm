# ReliableForm React Stack + Frontend SRE — Design Spec

**Date:** 2026-06-16
**Status:** Approved (brainstorming)

## Goal

Replicate JotForm's frontend architecture in ReliableForm at a right-sized
scale: turn the **builder** and **dashboard** into React SPAs, keep the
**public form** server-rendered (SSR) PHP, and bake **frontend SRE**
(real-user monitoring, browser→backend trace propagation, JS error
reporting) into the app bootstrap so we can define and measure client-side
SLIs/SLOs — the entire reason ReliableForm exists.

## Why this shape (verified against real JotForm)

Three exploration passes over the real JotForm codebase confirmed (~85%
confidence) that JotForm uses a **hybrid / strangler** architecture, and that
it is the dominant pattern, not a legacy corner:

- **Rich product surfaces** (form-builder, listings/MyForms, sheets, workflow,
  portal) are **React SPAs** (~100 apps) bootstrapped by a PHP shell
  (`FrontendLoader`) that injects config into `window.__jfrouter`, then React
  mounts on `#root`.
- **Public classic/card forms** (`jotform.com/{formID}`) are **server-rendered
  HTML** via the actively-maintained `for-form-render` JSVM path — React on the
  server, legacy JS on the client, not a client React SPA.
- **Frontend SRE is part of the bootstrap convention:** every app's entry does
  `import '@jotforminc/router-bridge/init'; import '@jotforminc/tracking/init';`
  and the PHP shell injects a trace-parent header patch and a Core Web Vitals
  performance tracker (`for-performance-tracker` → `/API/hack-vitals`). There is
  **no browser OpenTelemetry SDK** — tracing is a cheap custom header.

We mirror this exactly, scaled down: two React apps instead of 109, server-rendered
public forms, and observability baked into the bootstrap and wired to
ReliableForm's existing StatsD + OTel pipeline.

## Non-goals (YAGNI)

- No pnpm-monorepo build orchestrator (no Zenith equivalent).
- No rewrite of the public form to React; it stays PHP-SSR.
- No rewrite of business logic, DB schema, queues, or workers.
- No browser OpenTelemetry SDK (use the trace-header approach, like JotForm).
- No new design system / UI-kit dependency; reuse existing `app.css` tokens.
- No migration of login/register/landing/home off PHP.

## Locked technology decisions

| Concern | Decision | Rationale |
|---|---|---|
| Workspace | pnpm workspace at `frontend/` | JotForm-style package boundaries, right-sized |
| Bundler | Rspack | JotForm's actual bundler |
| Language | TypeScript | sanity; JotForm's newer libs are TS |
| Builder state | Redux Toolkit | JotForm builder uses Redux; RTK trims boilerplate |
| Dashboard state | React hooks / light context | dashboard is simpler; no Redux needed |
| Routing | react-router | JotForm uses react-router |
| Styling | reuse existing `apps/web/public/assets/css/app.css` tokens in a small `ui` lib | no heavyweight kit |
| HTTP client | axios wrapper (`request-layer`) with interceptors + envelope unwrap | mirrors JotForm RequestLayer |
| Tracing | W3C `traceparent` header on every fetch/XHR | backend already adopts traceparent; no OTEL browser SDK |
| RUM | `web-vitals` npm package → beacon | standard CWV, server-controlled sampling |

## Architecture

### Repo layout (new `frontend/` workspace; `apps/web` PHP stays)

```
frontend/                          # pnpm workspace, Rspack, TypeScript
  package.json                     # workspace root
  pnpm-workspace.yaml
  packages/
    apps/
      builder/                     # React SPA — form builder (replaces builder.php + builder.js)
      dashboard/                   # React SPA — forms list, submissions, settings
    libs/
      request-layer/               # axios client + interceptors + {ok,...} envelope
      router-bridge/               # reads window.__rfrouter (env, assetPath, revision, traceId, csrf)
      telemetry/                   # web-vitals + trace-header patch + JS error beacon
      form-fields/                 # field components shared by builder preview + (future) renderer
      ui/                          # small component kit built on app.css tokens
  build/                           # Rspack output (revision-hashed), served by nginx /build/
    asset-manifest.json
```

### Runtime flow (JotForm FrontendLoader pattern)

1. A PHP route (e.g. `GET /forms/{id}/edit`, `GET /dashboard`) calls
   `FrontendLoader::loadApp('builder'|'dashboard')`.
2. `FrontendLoader` renders a minimal HTML shell that:
   - injects `window.__rfrouter = { env, assetPath, revision, traceId, csrf, basePath }`
   - injects prefetched JSON bootstrap (e.g. the form definition for the builder)
     into a `<script type="application/json" id="rf-bootstrap">` tag — avoids a
     first-paint API waterfall (JotForm `JotFormLoader` pattern)
   - emits `<div id="root"></div>` + hashed `<script defer>` tags read from
     `build/asset-manifest.json`
   - emits the telemetry snippet (or it is bundled into the app entry)
3. React mounts on `#root`. `router-bridge` reads `window.__rfrouter`.
   `request-layer` calls `/v1/*` with `withCredentials` (session cookie).
4. `telemetry/init` runs on boot: patches `fetch`/`XHR` to attach `traceparent`,
   subscribes to `web-vitals`, installs `window.onerror`/`unhandledrejection`
   handlers; beacons go to `/v1/rum` and `/v1/clientlog`.

### Surfaces

| Surface | Tech | Replaces / status |
|---|---|---|
| builder | React SPA (Redux Toolkit) | replaces `builder.php` + `builder.js`; field palette, reorder, options, conditional logic |
| dashboard | React SPA (hooks) | replaces `dashboard.php`, `submissions.php`, `submission_detail.php`, `form_settings.php` |
| public form | PHP-SSR (unchanged) | `public_form.php` / `public_submit.php` stay; telemetry snippet injected |
| login / register / landing / home | PHP (unchanged) | telemetry snippet injected |

Conditional-logic note: today logic is duplicated in `conditions.php` (server)
and `app.js` (client) and they must stay in sync. The React builder + the
SSR public form must continue to agree. The single source of truth stays the
**server** (`conditions.php`); `form-fields` carries a TS port that mirrors it,
and an existing-or-new parity test guards both. We do NOT change the public
form's evaluation semantics in this work.

## Frontend SRE (the point)

### Client SLIs (new)

- **Loading:** LCP, TTFB (Core Web Vitals)
- **Interactivity:** INP, CLS
- **Reliability:** JS error rate (errors / page views)
- **API as seen by the browser:** request latency and error rate per endpoint
- **Journey success:** builder save success rate; public-form submit success
  rate (browser-observed, complements server-side counters)

### SLOs (initial targets — tune after baseline)

- LCP p75 < 2.5s
- INP p75 < 200ms
- CLS p75 < 0.1
- Public-form submit success (browser-observed) > 99%
- Builder save success > 99.5%

### New endpoints

Both follow the existing `/v1` envelope and instrumentation (they are
`api.*`-family routes added to `apps/web/src/api/dispatch.php`).

**`POST /v1/rum`** — accepts a web-vitals beacon:
```json
{ "metric": "LCP", "value": 1820.5, "id": "v3-...", "page": "builder",
  "nav": "navigate", "rating": "good" }
```
→ StatsD timing `web.client.lcp` (and `inp`, `cls`, `ttfb`, `fcp`) tagged by
page/rating → optional OTel span attributes. Sampled server-side via an `.env`
knob. Returns `204` (beacon-friendly). Accepts `navigator.sendBeacon`
(`Content-Type: text/plain` body parsed as JSON) and `fetch` POST.

**`POST /v1/clientlog`** — accepts a JS error/rejection:
```json
{ "kind": "error", "message": "...", "stack": "...", "page": "dashboard",
  "url": "...", "line": 12, "col": 5, "ua": "..." }
```
→ `AppLog::event('client_error', ...)` jsonl + StatsD counter
`web.client.error` tagged by page/kind. Returns `204`. Rate-limited per IP
(reuse the Redis limiter, fail-open).

### Trace propagation

The browser generates/continues a `traceparent` and sends it on every
same-origin API call. The backend already adopts W3C `traceparent` on ingress
(`lib/Trace.php`), so browser→PHP→workers becomes one trace. The shell seeds
the page's trace id into `window.__rfrouter.traceId` (rendered from the PHP
request's trace) so the first navigation correlates with the server render.

### Surfacing

- Extend `docs/METRICS.md` with the `web.client.*` family and
  `docs/SRE-GUIDE.md` with the client SLI/SLO definitions.
- Add client-vitals panels to the `apps/status` dashboard (it already reads
  `/api/stats`).

## Backend changes

### Unchanged
`lib/` kernel (Metrics, Otel, Trace, AppLog, DB, Auth); public-form SSR
(`public_form.php`, `public_submit.php`); login/register/landing/home; StatsD +
OTel shipper + nginx access logs.

### Modified
- **`apps/web/public/index.php`** — routes `/dashboard`, `/forms/new`,
  `/forms/{id}/edit`, `/forms/{id}/submissions`, `/forms/{id}/settings`,
  `/submissions/{id}` serve the React shell via `FrontendLoader` instead of
  `render_page(...)`. (PHP views for these are retained until the SPA reaches
  parity, behind a flag, then removed.)
- **`lib/Csrf.php`** — also accept the token via `X-CSRF-Token` header (for SPA
  fetch), in addition to the `_token` form field. `hash_equals` comparison
  unchanged.
- **`apps/web/src/api/dispatch.php` / `support.php` (`api_authenticate`)** —
  accept **session-cookie auth** in addition to Bearer/X-Api-Key, so the
  logged-in SPA can call `/v1`. This relaxes the documented "sessionless"
  contract for cookie-bearing same-origin requests only; API-key clients are
  unaffected. CSRF is enforced on cookie-authenticated mutating `/v1` calls
  (via `X-CSRF-Token`); API-key calls remain CSRF-exempt.
- **`config/nginx.conf.template`** — add `location /build/ { root ...; expires 1y; immutable }`
  for revision-hashed React bundles (long cache; hashes bust).
- **`apps/web/src/views/layout.php`** + public/login/landing views — inject the
  telemetry snippet (small inline loader or a `/build/telemetry.*.js` tag).

### New
- **`apps/web/src/FrontendLoader.php`** (+ shell template
  `apps/web/src/views/spa_shell.php`) — renders the React host page; reads
  `build/asset-manifest.json`; injects `window.__rfrouter`, the
  `rf-bootstrap` JSON, and hashed script tags.
- **`GET /v1/me`** already exists; add the SPA-facing CRUD it lacks:
  - `POST /v1/forms` (create), `PUT /v1/forms/{public_id}` (update
    title/description/fields/conditions), `DELETE /v1/forms/{public_id}`
  - `GET /v1/forms/{public_id}` already returns the definition (reused by builder)
  - `GET /v1/forms/{public_id}/settings`, `PUT /v1/forms/{public_id}/settings`
    (publish toggle, limits, thank-you, autoresponder, webhook URL)
  - `GET /v1/forms/{public_id}/submissions` already exists (reused by dashboard)
  All reuse existing validation (`validation.php`) and the existing envelope.
- **`POST /v1/rum`**, **`POST /v1/clientlog`** (above).
- **`docs/superpowers/`** spec (this file) and plan(s).

## Data flow (target)

```
Browser
  ├─ GET /dashboard ─────────▶ PHP FrontendLoader ─▶ shell HTML
  │                              (window.__rfrouter + bootstrap JSON + /build/*.js)
  ├─ React mounts ───────────▶ request-layer ─▶ /v1/* (cookie + X-CSRF-Token, traceparent)
  ├─ web-vitals ─────────────▶ POST /v1/rum ─▶ StatsD web.client.* + OTel
  ├─ JS errors ──────────────▶ POST /v1/clientlog ─▶ AppLog + StatsD web.client.error
  └─ GET /f/{id} (public) ───▶ PHP SSR (unchanged) + telemetry snippet
```

## Testing strategy

- **Backend (PHP):** unit/integration tests for new `/v1` CRUD, settings,
  `/v1/rum`, `/v1/clientlog` (envelope shape, auth modes, CSRF on cookie auth,
  validation reuse, StatsD/AppLog side-effects). Follow `docs/TESTING.md`.
- **FrontendLoader:** test it emits valid shell with manifest assets + bootstrap.
- **Frontend (libs):** unit tests for `request-layer` (interceptors, envelope
  unwrap, error mapping), `telemetry` (traceparent attach, beacon payloads),
  `router-bridge` (window read).
- **Apps:** component tests for builder reducers/conditional-logic parity and
  dashboard data hooks.
- **Parity:** conditional-logic parity test across server `conditions.php` and
  the `form-fields` TS port.

## Rollout / risk

- Strangler: ship behind a per-route flag; keep PHP views until SPA parity, then
  remove. Public form path is never at risk (untouched).
- The `/v1` cookie-auth relaxation is the main contract change — gated to
  same-origin cookie requests and CSRF-protected for mutations.
- Asset cache busting via revision hash + `asset-manifest.json` avoids stale
  bundles.

## Open implementation notes

- Revision strategy: a `build/asset-manifest.json` mapping entry → hashed file;
  `FrontendLoader` reads it. A build writes a `REVISION` file the shell exposes
  as `window.__rfrouter.revision` (also a Sentry-style release tag if/when error
  tracking gains a UI).
- Commits: per repo owner preference, the implementation plan uses
  **verification checkpoints instead of git commits**.
```
