# Frontend Foundation + SRE Implementation Plan (Plan 1 of 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Repo owner preference:** do NOT run `git commit`. Where this plan would normally commit, run the listed verification instead and check the box.

**Goal:** Stand up the JotForm-style frontend toolchain and land frontend SRE (RUM + browser→backend tracing + JS error reporting) on ReliableForm's existing pages, plus the PHP shell + HTTP/telemetry libs that the builder and dashboard SPAs (Plans 2–3) will build on.

**Architecture:** A new `frontend/` pnpm workspace (Rspack, TypeScript) produces revision-hashed bundles served by nginx at `/build/`. A new `FrontendLoader.php` renders a React host shell injecting `window.__rfrouter`. Shared libs `request-layer`, `router-bridge`, `telemetry`, `ui` are published in the workspace. Two new sessionless-friendly `/v1` endpoints (`/v1/rum`, `/v1/clientlog`) sink browser metrics into the existing StatsD/OTel/AppLog pipeline. The telemetry bundle is injected into existing PHP pages so SRE data flows before any SPA exists.

**Tech Stack:** pnpm, Rspack, TypeScript, axios, web-vitals, PHP 8 (existing kernel: Metrics/Otel/Trace/AppLog), nginx.

**Plan sequence:** Plan 1 Foundation (this) → Plan 2 Dashboard SPA → Plan 3 Builder SPA. See `docs/superpowers/specs/2026-06-16-reliableform-react-stack-design.md`.

---

## File Structure

Created:
- `frontend/package.json`, `frontend/pnpm-workspace.yaml`, `frontend/.npmrc`
- `frontend/tsconfig.base.json`
- `frontend/packages/libs/request-layer/{package.json,tsconfig.json,src/index.ts,src/types.ts,src/__tests__/index.test.ts}`
- `frontend/packages/libs/router-bridge/{package.json,tsconfig.json,src/index.ts,src/__tests__/index.test.ts}`
- `frontend/packages/libs/telemetry/{package.json,tsconfig.json,src/index.ts,src/vitals.ts,src/tracing.ts,src/errors.ts,src/__tests__/*.test.ts}`
- `frontend/packages/libs/ui/{package.json,tsconfig.json,src/index.ts}`
- `frontend/packages/apps/telemetry-entry/{package.json,rspack.config.js,src/index.ts}` (the injectable telemetry bundle)
- `apps/web/src/FrontendLoader.php`
- `apps/web/src/views/spa_shell.php`
- `apps/web/src/api/rum.php`, `apps/web/src/api/clientlog.php`
- `tests/api/RumEndpointTest.php`, `tests/api/ClientlogEndpointTest.php`, `tests/web/FrontendLoaderTest.php`

Modified:
- `apps/web/src/api/dispatch.php` (route `/v1/rum`, `/v1/clientlog`)
- `apps/web/src/support.php` (allow sessionless beacon endpoints; helper)
- `config/nginx.conf.template` (add `location /build/`)
- `apps/web/src/views/layout.php` (inject telemetry bundle tag)
- `.env.example` (add `RUM_SAMPLE_RATE`, `CLIENTLOG_ENABLED`)
- `docs/METRICS.md`, `docs/SRE-GUIDE.md` (document `web.client.*`)

---

## Task 1: Backend — `POST /v1/rum` endpoint

**Files:**
- Create: `apps/web/src/api/rum.php`
- Modify: `apps/web/src/api/dispatch.php`
- Test: `tests/api/RumEndpointTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/api/RumEndpointTest.php — follows the existing tests/ harness in docs/TESTING.md
declare(strict_types=1);

final class RumEndpointTest extends ApiTestCase
{
    public function test_accepts_sendbeacon_payload_and_returns_204(): void
    {
        $res = $this->request('POST', '/v1/rum', [
            'body' => json_encode([
                'metric' => 'LCP', 'value' => 1820.5, 'id' => 'v3-1',
                'page' => 'dashboard', 'nav' => 'navigate', 'rating' => 'good',
            ]),
            'headers' => ['Content-Type: text/plain'], // sendBeacon default
        ]);
        $this->assertSame(204, $res->status);
        $this->assertSame('', $res->body);
        $this->assertStatsd('web.client.lcp'); // timing emitted
    }

    public function test_rejects_unknown_metric_with_204_and_no_statsd(): void
    {
        $res = $this->request('POST', '/v1/rum', [
            'body' => json_encode(['metric' => 'BOGUS', 'value' => 1]),
            'headers' => ['Content-Type: application/json'],
        ]);
        $this->assertSame(204, $res->status);
        $this->assertNoStatsd('web.client.bogus');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php --filter RumEndpointTest`
Expected: FAIL — route `/v1/rum` returns 404 `not_found`.

- [ ] **Step 3: Add the route in `dispatch.php`**

Insert into the route-matching chain in `apps/web/src/api/dispatch.php` (after the `/v1/me` branch):

```php
} elseif ($path === '/v1/rum') {
    $apiHandlers = ['POST' => ['POST /v1/rum', 'rum']];
} elseif ($path === '/v1/clientlog') {
    $apiHandlers = ['POST' => ['POST /v1/clientlog', 'clientlog']];
```

These two endpoints are beacons: they must skip `api_authenticate()`. After the
`[$routePattern, $apiEndpoint] = $apiHandlers[$method];` line, guard the auth call:

```php
$beaconEndpoints = ['rum', 'clientlog'];
if (!in_array($apiEndpoint, $beaconEndpoints, true)) {
    $apiUser = api_authenticate();
}
require __DIR__ . '/' . $apiEndpoint . '.php';
```

- [ ] **Step 4: Implement `apps/web/src/api/rum.php`**

```php
<?php
declare(strict_types=1);

// Browser web-vitals beacon sink. Sessionless, returns 204. Body may arrive as
// application/json or text/plain (navigator.sendBeacon). Server-side sampling.

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);

http_response_code(204);
header_remove('Content-Type');

if (!is_array($payload)) {
    Metrics::increment('web.client.rum_malformed');
    exit;
}

$sample = (float) (getenv('RUM_SAMPLE_RATE') ?: '1.0');
if ($sample < 1.0 && mt_rand() / mt_getrandmax() > $sample) {
    exit; // dropped by sampling
}

$allowed = ['LCP' => 'lcp', 'INP' => 'inp', 'CLS' => 'cls', 'TTFB' => 'ttfb', 'FCP' => 'fcp', 'FID' => 'fid'];
$metric  = is_string($payload['metric'] ?? null) ? $payload['metric'] : '';
if (!isset($allowed[$metric]) || !is_numeric($payload['value'] ?? null)) {
    exit;
}

$page   = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($payload['page'] ?? 'unknown'))) ?: 'unknown';
$rating = in_array($payload['rating'] ?? '', ['good', 'needs-improvement', 'poor'], true) ? $payload['rating'] : 'unrated';
$value  = (float) $payload['value'];

// CLS is unitless (×1000 for integer-friendly StatsD); others are milliseconds.
$statValue = $metric === 'CLS' ? (int) round($value * 1000) : (int) round($value);

Metrics::timing("web.client.{$allowed[$metric]}", $statValue, ['page' => $page, 'rating' => $rating]);
Otel::span('rum.' . $allowed[$metric], microtime(true), microtime(true), Otel::KIND_SERVER, [
    'rf.page' => $page, 'rf.metric' => $metric, 'rf.value' => $value, 'rf.rating' => $rating,
], false);
Otel::flush();
exit;
```

> Note: if `Metrics::timing` does not accept a tags array in this codebase, fall
> back to dot-suffixed names: `web.client.lcp.{page}`. Confirm the signature in
> `lib/Metrics.php` before implementing and match it.

- [ ] **Step 5: Run test to verify it passes**

Run: `php tests/run.php --filter RumEndpointTest`
Expected: PASS.

- [ ] **Step 6: Verification checkpoint (no commit)**

Run: `php tests/run.php --filter RumEndpointTest` → all green. Note files touched.

---

## Task 2: Backend — `POST /v1/clientlog` endpoint

**Files:**
- Create: `apps/web/src/api/clientlog.php`
- Test: `tests/api/ClientlogEndpointTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

final class ClientlogEndpointTest extends ApiTestCase
{
    public function test_records_error_and_returns_204(): void
    {
        $res = $this->request('POST', '/v1/clientlog', [
            'body' => json_encode([
                'kind' => 'error', 'message' => 'boom', 'stack' => 'at x',
                'page' => 'builder', 'url' => 'http://localhost:8080/forms/new',
            ]),
            'headers' => ['Content-Type: application/json'],
        ]);
        $this->assertSame(204, $res->status);
        $this->assertAppLogEvent('client_error');
        $this->assertStatsd('web.client.error');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php --filter ClientlogEndpointTest`
Expected: FAIL — `clientlog.php` missing (route added in Task 1 Step 3).

- [ ] **Step 3: Implement `apps/web/src/api/clientlog.php`**

```php
<?php
declare(strict_types=1);

// Browser JS error/rejection sink. Sessionless, 204. Per-IP rate-limited
// (reuse Redis limiter, fail-open). Truncates to keep logs sane.

http_response_code(204);
header_remove('Content-Type');

if ((getenv('CLIENTLOG_ENABLED') ?: '1') !== '1') {
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    Metrics::increment('web.client.clientlog_malformed');
    exit;
}

// Fail-open per-IP rate limit: 60/min.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!RateLimit::allow("clientlog:$ip", 60, 60)) { // returns true on Redis error
    Metrics::increment('web.client.clientlog_ratelimited');
    exit;
}

$kind = in_array($payload['kind'] ?? '', ['error', 'unhandledrejection'], true) ? $payload['kind'] : 'error';
$page = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($payload['page'] ?? 'unknown'))) ?: 'unknown';

AppLog::event('client_error', [
    'kind'    => $kind,
    'page'    => $page,
    'message' => mb_substr((string) ($payload['message'] ?? ''), 0, 500),
    'stack'   => mb_substr((string) ($payload['stack'] ?? ''), 0, 4000),
    'url'     => mb_substr((string) ($payload['url'] ?? ''), 0, 500),
    'line'    => (int) ($payload['line'] ?? 0),
    'col'     => (int) ($payload['col'] ?? 0),
    'ua'      => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
]);
Metrics::increment('web.client.error', ['page' => $page, 'kind' => $kind]);
exit;
```

> Confirm the helper names `RateLimit::allow` and `AppLog::event` against
> `lib/` before implementing; match the real signatures (the API rate limiter
> lives near `api_authenticate` in `support.php`/`lib/`). If no reusable
> `RateLimit` class exists, inline the same Redis INCR+EXPIRE pattern used for
> `ratelimit:api:<key>` with a fail-open catch.

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php --filter ClientlogEndpointTest`
Expected: PASS.

- [ ] **Step 5: Add `.env.example` knobs**

Add under the observability block in `.env.example`:

```
# Frontend SRE
RUM_SAMPLE_RATE=1.0
CLIENTLOG_ENABLED=1
```

- [ ] **Step 6: Verification checkpoint (no commit)**

Run: `php tests/run.php --filter ClientlogEndpointTest` → green.

---

## Task 3: pnpm workspace scaffold

**Files:**
- Create: `frontend/package.json`, `frontend/pnpm-workspace.yaml`, `frontend/.npmrc`, `frontend/tsconfig.base.json`

- [ ] **Step 1: Create `frontend/pnpm-workspace.yaml`**

```yaml
packages:
  - "packages/apps/*"
  - "packages/libs/*"
```

- [ ] **Step 2: Create `frontend/package.json`**

```json
{
  "name": "reliableform-frontend",
  "private": true,
  "scripts": {
    "build": "pnpm -r --filter \"./packages/apps/*\" build",
    "test": "pnpm -r test",
    "typecheck": "pnpm -r typecheck"
  },
  "devDependencies": {
    "@rspack/cli": "^1.1.0",
    "@rspack/core": "^1.1.0",
    "typescript": "^5.5.0",
    "vitest": "^2.1.0"
  }
}
```

- [ ] **Step 3: Create `frontend/.npmrc`**

```
auto-install-peers=true
```

- [ ] **Step 4: Create `frontend/tsconfig.base.json`**

```json
{
  "compilerOptions": {
    "target": "ES2020",
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "lib": ["ES2020", "DOM", "DOM.Iterable"],
    "strict": true,
    "declaration": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "types": ["vitest/globals"]
  }
}
```

- [ ] **Step 5: Install and verify**

Run: `cd frontend && pnpm install`
Expected: lockfile created, no errors.

- [ ] **Step 6: Verification checkpoint (no commit)**

Confirm `frontend/pnpm-lock.yaml` exists and `pnpm -r test` runs (no packages yet → no-op success).

---

## Task 4: `router-bridge` lib

**Files:**
- Create: `frontend/packages/libs/router-bridge/{package.json,tsconfig.json,src/index.ts,src/__tests__/index.test.ts}`

- [ ] **Step 1: Write the failing test**

```ts
// src/__tests__/index.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import { getRouterValue, getAssetPath, getTraceId, getCsrf } from '../index';

describe('router-bridge', () => {
  beforeEach(() => {
    (globalThis as any).window = { __rfrouter: {
      ENV: 'test', ASSET_PATH: '/build/', REVISION: 'abc123',
      TRACE_ID: 'deadbeef', CSRF: 'tok', BASE_PATH: '/',
    } };
  });
  it('reads values with fallback', () => {
    expect(getRouterValue('ENV', 'x')).toBe('test');
    expect(getRouterValue('MISSING', 'fallback')).toBe('fallback');
    expect(getAssetPath()).toBe('/build/');
    expect(getTraceId()).toBe('deadbeef');
    expect(getCsrf()).toBe('tok');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend/packages/libs/router-bridge && pnpm test`
Expected: FAIL — module not found.

- [ ] **Step 3: Create `package.json`**

```json
{
  "name": "@rf/router-bridge",
  "version": "0.0.0",
  "type": "module",
  "main": "src/index.ts",
  "scripts": { "test": "vitest run", "typecheck": "tsc --noEmit" },
  "devDependencies": { "typescript": "^5.5.0", "vitest": "^2.1.0" }
}
```

- [ ] **Step 4: Create `tsconfig.json`**

```json
{ "extends": "../../../tsconfig.base.json", "include": ["src"] }
```

- [ ] **Step 5: Implement `src/index.ts`**

```ts
export interface RfRouter {
  ENV: string; ASSET_PATH: string; REVISION: string;
  TRACE_ID: string; CSRF: string; BASE_PATH: string;
  [k: string]: string;
}

const router = (): Partial<RfRouter> =>
  ((globalThis as any).window?.__rfrouter ?? {}) as Partial<RfRouter>;

export function getRouterValue(key: string, fallback = ''): string {
  const v = router()[key];
  return typeof v === 'string' ? v : fallback;
}
export const getAssetPath = () => getRouterValue('ASSET_PATH', '/');
export const getTraceId = () => getRouterValue('TRACE_ID', '');
export const getCsrf = () => getRouterValue('CSRF', '');
export const getBasePath = () => getRouterValue('BASE_PATH', '/');
export const getRevision = () => getRouterValue('REVISION', 'dev');
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd frontend/packages/libs/router-bridge && pnpm test`
Expected: PASS.

- [ ] **Step 7: Verification checkpoint (no commit)**

---

## Task 5: `request-layer` lib

**Files:**
- Create: `frontend/packages/libs/request-layer/{package.json,tsconfig.json,src/types.ts,src/index.ts,src/__tests__/index.test.ts}`

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect, vi } from 'vitest';
import { RequestLayer } from '../index';

describe('request-layer', () => {
  it('unwraps {ok:true,...} success envelope', async () => {
    const adapter = vi.fn(async () => ({
      status: 200, data: { ok: true, forms: [{ id: 1 }] },
    }));
    const r = new RequestLayer('/v1', { adapter });
    const out = await r.get<{ forms: unknown[] }>('/forms');
    expect(out.forms).toEqual([{ id: 1 }]);
  });

  it('rejects with mapped error on {ok:false}', async () => {
    const adapter = vi.fn(async () => ({
      status: 422,
      data: { ok: false, error: { code: 'validation_failed', message: 'bad', trace_id: 't', fields: { a: 'req' } } },
    }));
    const r = new RequestLayer('/v1', { adapter });
    await expect(r.post('/forms', {})).rejects.toMatchObject({
      code: 'validation_failed', traceId: 't', fields: { a: 'req' }, status: 422,
    });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend/packages/libs/request-layer && pnpm test`
Expected: FAIL — module not found.

- [ ] **Step 3: Create `package.json`**

```json
{
  "name": "@rf/request-layer",
  "version": "0.0.0",
  "type": "module",
  "main": "src/index.ts",
  "scripts": { "test": "vitest run", "typecheck": "tsc --noEmit" },
  "dependencies": { "axios": "^1.7.0", "@rf/router-bridge": "workspace:*" },
  "devDependencies": { "typescript": "^5.5.0", "vitest": "^2.1.0" }
}
```

- [ ] **Step 4: Create `tsconfig.json`**

```json
{ "extends": "../../../tsconfig.base.json", "include": ["src"] }
```

- [ ] **Step 5: Implement `src/types.ts`**

```ts
export interface ApiError {
  code: string; message: string; traceId: string;
  status: number; fields?: Record<string, string>;
}
export interface AdapterResponse { status: number; data: any; }
export type Adapter = (cfg: {
  method: string; url: string; data?: unknown; headers: Record<string, string>;
}) => Promise<AdapterResponse>;
```

- [ ] **Step 6: Implement `src/index.ts`**

```ts
import axios from 'axios';
import { getCsrf } from '@rf/router-bridge';
import type { Adapter, ApiError } from './types';

export class RequestLayer {
  private adapter: Adapter;
  constructor(private baseURL: string, opts?: { adapter?: Adapter }) {
    this.adapter = opts?.adapter ?? this.axiosAdapter();
  }
  private axiosAdapter(): Adapter {
    const inst = axios.create({ baseURL: this.baseURL, withCredentials: true, validateStatus: () => true });
    return async (cfg) => {
      const res = await inst.request({ method: cfg.method, url: cfg.url, data: cfg.data, headers: cfg.headers });
      return { status: res.status, data: res.data };
    };
  }
  private headers(method: string): Record<string, string> {
    const h: Record<string, string> = { 'Content-Type': 'application/json' };
    if (method !== 'GET') h['X-CSRF-Token'] = getCsrf();
    return h;
  }
  private unwrap(res: { status: number; data: any }): any {
    const d = res.data;
    if (d && d.ok === false) {
      const e: ApiError = {
        code: d.error?.code ?? 'internal_error',
        message: d.error?.message ?? 'Request failed',
        traceId: d.error?.trace_id ?? '',
        status: res.status,
        fields: d.error?.fields,
      };
      throw e;
    }
    return d;
  }
  async get<T = any>(url: string): Promise<T> {
    return this.unwrap(await this.adapter({ method: 'GET', url, headers: this.headers('GET') }));
  }
  async post<T = any>(url: string, data: unknown): Promise<T> {
    return this.unwrap(await this.adapter({ method: 'POST', url, data, headers: this.headers('POST') }));
  }
  async put<T = any>(url: string, data: unknown): Promise<T> {
    return this.unwrap(await this.adapter({ method: 'PUT', url, data, headers: this.headers('PUT') }));
  }
  async del<T = any>(url: string): Promise<T> {
    return this.unwrap(await this.adapter({ method: 'DELETE', url, headers: this.headers('DELETE') }));
  }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `cd frontend/packages/libs/request-layer && pnpm test`
Expected: PASS (both cases).

- [ ] **Step 8: Verification checkpoint (no commit)**

---

## Task 6: `telemetry` lib (vitals + tracing + errors)

**Files:**
- Create: `frontend/packages/libs/telemetry/{package.json,tsconfig.json,src/tracing.ts,src/vitals.ts,src/errors.ts,src/index.ts,src/__tests__/tracing.test.ts,src/__tests__/payloads.test.ts}`

- [ ] **Step 1: Write the failing tests**

```ts
// src/__tests__/tracing.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { installTraceHeader, makeTraceparent } from '../tracing';

describe('tracing', () => {
  beforeEach(() => { (globalThis as any).window = { __rfrouter: { TRACE_ID: '' } }; });
  it('makeTraceparent yields a valid W3C header', () => {
    expect(makeTraceparent()).toMatch(/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/);
  });
  it('installTraceHeader patches fetch to add traceparent for same-origin', async () => {
    const calls: any[] = [];
    (globalThis as any).fetch = vi.fn(async (_u: string, init: any) => { calls.push(init); return { ok: true }; });
    (globalThis as any).location = { origin: 'http://localhost' };
    installTraceHeader();
    await (globalThis as any).fetch('http://localhost/v1/me', {});
    const h = new Headers(calls[0].headers);
    expect(h.get('traceparent')).toMatch(/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/);
  });
});
```

```ts
// src/__tests__/payloads.test.ts
import { describe, it, expect } from 'vitest';
import { vitalPayload } from '../vitals';
import { errorPayload } from '../errors';

describe('payloads', () => {
  it('vitalPayload shapes a beacon body', () => {
    const p = vitalPayload({ name: 'LCP', value: 1820.5, id: 'v3', rating: 'good', navigationType: 'navigate' } as any, 'dashboard');
    expect(p).toEqual({ metric: 'LCP', value: 1820.5, id: 'v3', rating: 'good', nav: 'navigate', page: 'dashboard' });
  });
  it('errorPayload truncates and tags the page', () => {
    const p = errorPayload({ message: 'x'.repeat(600), stack: 's', filename: 'f', lineno: 1, colno: 2 } as any, 'builder');
    expect(p.page).toBe('builder');
    expect(p.kind).toBe('error');
    expect(p.message.length).toBe(500);
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd frontend/packages/libs/telemetry && pnpm test`
Expected: FAIL — modules not found.

- [ ] **Step 3: Create `package.json`**

```json
{
  "name": "@rf/telemetry",
  "version": "0.0.0",
  "type": "module",
  "main": "src/index.ts",
  "scripts": { "test": "vitest run", "typecheck": "tsc --noEmit" },
  "dependencies": { "web-vitals": "^4.2.0", "@rf/router-bridge": "workspace:*" },
  "devDependencies": { "typescript": "^5.5.0", "vitest": "^2.1.0" }
}
```

- [ ] **Step 4: Create `tsconfig.json`**

```json
{ "extends": "../../../tsconfig.base.json", "include": ["src"] }
```

- [ ] **Step 5: Implement `src/tracing.ts`**

```ts
import { getTraceId } from '@rf/router-bridge';

const hex = (n: number) =>
  Array.from(crypto.getRandomValues(new Uint8Array(n)))
    .map((b) => b.toString(16).padStart(2, '0')).join('');

export function makeTraceparent(): string {
  const trace = getTraceId() && /^[0-9a-f]{32}$/.test(getTraceId()) ? getTraceId() : hex(16);
  return `00-${trace}-${hex(8)}-01`;
}

function sameOrigin(url: string): boolean {
  try { return new URL(url, location.href).origin === location.origin; }
  catch { return true; } // relative URL
}

export function installTraceHeader(): void {
  const origFetch = globalThis.fetch;
  globalThis.fetch = function (input: any, init: any = {}) {
    const url = typeof input === 'string' ? input : input?.url ?? '';
    if (sameOrigin(url)) {
      const headers = new Headers(init.headers || {});
      if (!headers.has('traceparent')) headers.set('traceparent', makeTraceparent());
      init = { ...init, headers };
    }
    return origFetch.call(this, input, init);
  } as typeof fetch;

  const XHR = globalThis.XMLHttpRequest;
  if (XHR) {
    const open = XHR.prototype.open;
    const send = XHR.prototype.send;
    XHR.prototype.open = function (this: any, method: string, url: string, ...rest: any[]) {
      this.__rf_url = url; return open.call(this, method, url, ...(rest as []));
    };
    XHR.prototype.send = function (this: any, body?: any) {
      if (sameOrigin(this.__rf_url || '')) {
        try { this.setRequestHeader('traceparent', makeTraceparent()); } catch {}
      }
      return send.call(this, body);
    };
  }
}
```

- [ ] **Step 6: Implement `src/vitals.ts`**

```ts
import { onLCP, onINP, onCLS, onTTFB, onFCP, type Metric } from 'web-vitals';

export interface VitalBeacon {
  metric: string; value: number; id: string; rating: string; nav: string; page: string;
}

export function vitalPayload(m: Metric, page: string): VitalBeacon {
  return { metric: m.name, value: m.value, id: m.id, rating: m.rating, nav: m.navigationType, page };
}

export function reportVitals(page: string, beacon: (b: VitalBeacon) => void): void {
  const send = (m: Metric) => beacon(vitalPayload(m, page));
  onLCP(send); onINP(send); onCLS(send); onTTFB(send); onFCP(send);
}
```

- [ ] **Step 7: Implement `src/errors.ts`**

```ts
export interface ErrorBeacon {
  kind: 'error' | 'unhandledrejection'; message: string; stack: string;
  url: string; line: number; col: number; page: string;
}

export function errorPayload(e: any, page: string): ErrorBeacon {
  return {
    kind: 'error',
    message: String(e?.message ?? e ?? '').slice(0, 500),
    stack: String(e?.stack ?? '').slice(0, 4000),
    url: String(e?.filename ?? location.href).slice(0, 500),
    line: Number(e?.lineno ?? 0), col: Number(e?.colno ?? 0), page,
  };
}

export function installErrorReporting(page: string, beacon: (b: ErrorBeacon) => void): void {
  window.addEventListener('error', (ev) => beacon(errorPayload(ev, page)));
  window.addEventListener('unhandledrejection', (ev: PromiseRejectionEvent) => {
    const p = errorPayload(ev.reason ?? {}, page); p.kind = 'unhandledrejection'; beacon(p);
  });
}
```

- [ ] **Step 8: Implement `src/index.ts`**

```ts
import { installTraceHeader } from './tracing';
import { reportVitals, type VitalBeacon } from './vitals';
import { installErrorReporting, type ErrorBeacon } from './errors';

function beaconTo(path: string) {
  return (body: VitalBeacon | ErrorBeacon) => {
    const data = JSON.stringify(body);
    if (navigator.sendBeacon) navigator.sendBeacon(path, data);
    else fetch(path, { method: 'POST', body: data, headers: { 'Content-Type': 'application/json' }, keepalive: true });
  };
}

export interface TelemetryOptions { page: string; rumPath?: string; clientlogPath?: string; }

export function initTelemetry(opts: TelemetryOptions): void {
  const rum = opts.rumPath ?? '/v1/rum';
  const clog = opts.clientlogPath ?? '/v1/clientlog';
  installTraceHeader();
  installErrorReporting(opts.page, beaconTo(clog));
  reportVitals(opts.page, beaconTo(rum));
}

export { installTraceHeader, reportVitals, installErrorReporting };
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `cd frontend/packages/libs/telemetry && pnpm test`
Expected: PASS.

- [ ] **Step 10: Verification checkpoint (no commit)**

---

## Task 7: `telemetry-entry` injectable bundle + Rspack

**Files:**
- Create: `frontend/packages/apps/telemetry-entry/{package.json,rspack.config.js,src/index.ts}`

- [ ] **Step 1: Create `package.json`**

```json
{
  "name": "@rf/telemetry-entry",
  "version": "0.0.0",
  "private": true,
  "scripts": { "build": "rspack build", "typecheck": "tsc --noEmit" },
  "dependencies": { "@rf/telemetry": "workspace:*", "@rf/router-bridge": "workspace:*" },
  "devDependencies": { "@rspack/cli": "^1.1.0", "@rspack/core": "^1.1.0", "typescript": "^5.5.0" }
}
```

- [ ] **Step 2: Implement `src/index.ts`**

```ts
import { initTelemetry } from '@rf/telemetry';
import { getRouterValue } from '@rf/router-bridge';

// Page name comes from a data attribute on <body data-rf-page="..."> or __rfrouter.
const page =
  document.body?.getAttribute('data-rf-page') ||
  getRouterValue('ACTIVE_PAGE', 'unknown');

initTelemetry({ page });
```

- [ ] **Step 3: Implement `rspack.config.js`**

```js
const path = require('path');
const { rspack } = require('@rspack/core');

module.exports = {
  entry: { telemetry: './src/index.ts' },
  output: {
    path: path.resolve(__dirname, '../../../build'),
    filename: '[name].[contenthash:8].js',
    clean: false,
  },
  resolve: { extensions: ['.ts', '.js'] },
  module: {
    rules: [{ test: /\.ts$/, use: { loader: 'builtin:swc-loader',
      options: { jsc: { parser: { syntax: 'typescript' } } } } }],
  },
  plugins: [
    // Emit/merge build/asset-manifest.json so FrontendLoader can resolve hashes.
    new rspack.container.ModuleFederationPlugin === undefined ? undefined : undefined,
  ].filter(Boolean),
  optimization: { minimize: true },
};
```

> The manifest is produced by a tiny post-build script rather than a plugin to
> keep deps minimal — see Step 4.

- [ ] **Step 4: Add a manifest writer**

Append to `package.json` scripts:

```json
"build": "rspack build && node ../../../scripts/write-manifest.mjs"
```

Create `frontend/scripts/write-manifest.mjs`:

```js
import { readdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const buildDir = new URL('../build/', import.meta.url).pathname;
const files = readdirSync(buildDir).filter((f) => f.endsWith('.js'));
const manifest = {};
for (const f of files) {
  const entry = f.replace(/\.[0-9a-f]{8}\.js$/, '.js'); // telemetry.js -> telemetry.[hash].js
  manifest[entry] = '/build/' + f;
}
writeFileSync(join(buildDir, 'asset-manifest.json'), JSON.stringify(manifest, null, 2));
console.log('wrote asset-manifest.json', manifest);
```

- [ ] **Step 5: Build and verify**

Run: `cd frontend && pnpm install && pnpm --filter @rf/telemetry-entry build`
Expected: `frontend/build/telemetry.<hash>.js` and `frontend/build/asset-manifest.json` exist; manifest maps `telemetry.js` → the hashed path.

- [ ] **Step 6: Verification checkpoint (no commit)**

---

## Task 8: `FrontendLoader.php` + shell + manifest reader

**Files:**
- Create: `apps/web/src/FrontendLoader.php`, `apps/web/src/views/spa_shell.php`
- Test: `tests/web/FrontendLoaderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

final class FrontendLoaderTest extends WebTestCase
{
    public function test_renders_shell_with_manifest_asset_and_bootstrap(): void
    {
        $html = FrontendLoader::render('dashboard', [
            'bootstrap' => ['forms' => []],
            'manifestPath' => $this->fixtureManifest(), // {"dashboard.js":"/build/dashboard.abcd1234.js"}
        ]);
        $this->assertStringContainsString('<div id="root"></div>', $html);
        $this->assertStringContainsString('/build/dashboard.abcd1234.js', $html);
        $this->assertStringContainsString('window.__rfrouter', $html);
        $this->assertStringContainsString('id="rf-bootstrap"', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php --filter FrontendLoaderTest`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement `apps/web/src/FrontendLoader.php`**

```php
<?php
declare(strict_types=1);

final class FrontendLoader
{
    /**
     * @param array{bootstrap?:array,manifestPath?:string,title?:string,csrf?:string} $opts
     */
    public static function render(string $app, array $opts = []): string
    {
        $manifestPath = $opts['manifestPath'] ?? (RF_ROOT . '/frontend/build/asset-manifest.json');
        $manifest = is_file($manifestPath)
            ? (json_decode((string) file_get_contents($manifestPath), true) ?: [])
            : [];

        $entries = ['telemetry.js']; // always inject telemetry first
        $appEntry = $app . '.js';
        if (isset($manifest[$appEntry])) {
            $entries[] = $appEntry;
        }
        $scripts = [];
        foreach ($entries as $e) {
            if (isset($manifest[$e])) {
                $scripts[] = $manifest[$e];
            }
        }

        $router = [
            'ENV'         => getenv('APP_ENV') ?: 'prod',
            'ASSET_PATH'  => '/build/',
            'REVISION'    => is_file(RF_ROOT . '/frontend/build/REVISION')
                                ? trim((string) file_get_contents(RF_ROOT . '/frontend/build/REVISION'))
                                : 'dev',
            'TRACE_ID'    => Trace::id(),         // confirm accessor name in lib/Trace.php
            'CSRF'        => $opts['csrf'] ?? Csrf::token(),
            'BASE_PATH'   => '/',
            'ACTIVE_PAGE' => $app,
        ];

        return render('spa_shell', [
            'title'     => $opts['title'] ?? 'ReliableForm',
            'router'    => $router,
            'bootstrap' => $opts['bootstrap'] ?? null,
            'scripts'   => $scripts,
            'app'       => $app,
        ]);
    }
}
```

> Confirm `Trace::id()` / `Csrf::token()` accessor names against `lib/`. The
> map at the top of `index.php` shows current routing; this loader replaces
> `render_page(...)` only for the SPA routes (wired in Plan 2/3).

- [ ] **Step 4: Implement `apps/web/src/views/spa_shell.php`**

```php
<?php /** @var array $router @var ?array $bootstrap @var array $scripts @var string $title @var string $app */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <script>window.__rfrouter = <?= json_encode($router, JSON_UNESCAPED_SLASHES) ?>;</script>
  <?php if ($bootstrap !== null): ?>
  <script type="application/json" id="rf-bootstrap"><?= json_encode($bootstrap, JSON_UNESCAPED_SLASHES) ?></script>
  <?php endif; ?>
</head>
<body data-rf-page="<?= e($app) ?>">
  <div id="root"></div>
  <?php foreach ($scripts as $src): ?>
  <script src="<?= e($src) ?>" defer></script>
  <?php endforeach; ?>
</body>
</html>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php tests/run.php --filter FrontendLoaderTest`
Expected: PASS.

- [ ] **Step 6: Verification checkpoint (no commit)**

---

## Task 9: nginx `/build/` location

**Files:**
- Modify: `config/nginx.conf.template`

- [ ] **Step 1: Add the location block**

Insert after the `/assets/` block (around line 73):

```nginx
        # Revision-hashed React bundles — content-addressed, cache hard.
        location /build/ {
            root {{ROOT}}/frontend;
            expires 1y;
            add_header Cache-Control "public, immutable";
            try_files $uri =404;
        }
```

- [ ] **Step 2: Verify config renders and serves**

Run: `./launch.sh` (or the project's restart path), then
`curl -I http://localhost:8080/build/asset-manifest.json`
Expected: `200 OK` with long cache headers (manifest is JSON, served from disk).

- [ ] **Step 3: Verification checkpoint (no commit)**

---

## Task 10: Inject telemetry on existing PHP pages

**Files:**
- Modify: `apps/web/src/views/layout.php`

- [ ] **Step 1: Add the telemetry tag + page marker**

In `layout.php`, set a page marker on `<body>` and emit the telemetry bundle
from the manifest. Near the top of `<body>`:

```php
<?php
$rfManifest = is_file(RF_ROOT . '/frontend/build/asset-manifest.json')
  ? (json_decode((string) file_get_contents(RF_ROOT . '/frontend/build/asset-manifest.json'), true) ?: [])
  : [];
$rfTelemetry = $rfManifest['telemetry.js'] ?? null;
$rfPage = $rfPage ?? 'web'; // page handlers can set $rfPage before render
?>
```

Add `data-rf-page="<?= e($rfPage) ?>"` to the existing `<body>` tag, and before
`</body>` (after existing scripts):

```php
<?php if ($rfTelemetry): ?>
<script>window.__rfrouter = window.__rfrouter || { TRACE_ID: '<?= e(Trace::id()) ?>', ASSET_PATH: '/build/' };</script>
<script src="<?= e($rfTelemetry) ?>" defer></script>
<?php endif; ?>
```

> This makes RUM + tracing + error reporting flow from the *current* PHP pages
> (dashboard, public form, login) before any SPA exists — the SRE win lands now.
> Confirm `Trace::id()` accessor; reuse whatever `layout.php` already uses for
> the trace id if present.

- [ ] **Step 2: Manual verification**

Build the bundle (`cd frontend && pnpm --filter @rf/telemetry-entry build`),
restart, open `http://localhost:8080/dashboard` (logged in) and the public form,
then:

Run: `tail -n 20 storage/logs/app-*.jsonl` after triggering a JS error in
devtools console (`throw new Error('rf-test')` won't auto-beacon; instead use a
broken inline call) — confirm a `client_error` event appears.
Confirm web-vitals POSTs to `/v1/rum` in the Network tab and StatsD receives
`web.client.lcp` (check the StatsD sink / metrics dashboard).

- [ ] **Step 3: Verification checkpoint (no commit)**

---

## Task 11: Document the new telemetry

**Files:**
- Modify: `docs/METRICS.md`, `docs/SRE-GUIDE.md`

- [ ] **Step 1: Add `web.client.*` to `docs/METRICS.md`**

Add a row group:

```
web.client.lcp / inp / cls / ttfb / fcp   timing   browser Core Web Vitals (POST /v1/rum), tagged page+rating
web.client.error                          counter  JS error / unhandledrejection (POST /v1/clientlog), tagged page+kind
web.client.rum_malformed                  counter  unparseable RUM beacon
web.client.clientlog_ratelimited          counter  clientlog beacon dropped by per-IP limit
```

- [ ] **Step 2: Add client SLIs/SLOs to `docs/SRE-GUIDE.md`**

Document the SLIs and initial SLO targets from the spec (LCP p75 < 2.5s, INP p75
< 200ms, CLS p75 < 0.1, submit success > 99%, builder save > 99.5%) and note the
browser→backend trace join via `traceparent`.

- [ ] **Step 3: Verification checkpoint (no commit)**

Run: full backend test pass `php tests/run.php` and `cd frontend && pnpm -r test` → all green.

---

## Self-Review (completed at authoring)

- **Spec coverage:** repo layout (T3–T7), FrontendLoader/shell (T8), nginx /build/ (T9),
  telemetry libs + injection (T4–T7, T10), `/v1/rum` + `/v1/clientlog` (T1–T2),
  docs/SLOs (T11). `/v1` CRUD + cookie-auth + the two SPAs are intentionally
  deferred to Plans 2–3 (this plan ships frontend SRE on existing pages).
- **Placeholder scan:** the only soft spots are explicit "confirm accessor name
  against `lib/`" notes for `Trace::id()`, `Csrf::token()`, `Metrics::timing`
  tags, and `RateLimit::allow` — kept because exact signatures must be read from
  the codebase at implementation time; each has a concrete fallback.
- **Type consistency:** `RequestLayer`, `initTelemetry`, `makeTraceparent`,
  `vitalPayload`, `errorPayload`, `FrontendLoader::render`, manifest key
  convention (`telemetry.js` → hashed) are consistent across tasks.

---

## Next plans

- **Plan 2 — Dashboard SPA:** `apps/dashboard` React app; reuse `GET /v1/forms`,
  `/v1/forms/{id}/submissions`; add cookie-session auth to `/v1` + CSRF header
  acceptance; route `/dashboard` etc. through `FrontendLoader`; component tests.
- **Plan 3 — Builder SPA:** `apps/builder` (Redux Toolkit) + `form-fields` lib;
  `/v1` forms CRUD + settings; conditional-logic TS port + server-parity test;
  route `/forms/new`, `/forms/{id}/edit` through `FrontendLoader`; retire
  `builder.php` + `builder.js`.
