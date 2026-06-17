// router-bridge — the PHP FrontendLoader injects window.__rfrouter; the SPA
// reads deploy/runtime config from it (mirrors JotForm's router-bridge).

export interface RfRouter {
  ENV: string;
  ASSET_PATH: string;
  REVISION: string;
  TRACE_ID: string;
  CSRF: string;
  BASE_PATH: string;
  ACTIVE_PAGE: string;
  API_KEY: string;
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
export const getActivePage = () => getRouterValue('ACTIVE_PAGE', 'unknown');
export const getApiKey = () => getRouterValue('API_KEY', '');

/**
 * Prefetched bootstrap blob the PHP shell injected as
 * <script type="application/json" id="rf-bootstrap">. Avoids a first-paint
 * API waterfall (JotForm's JotFormLoader pattern). Returns null when absent.
 */
export function getBootstrap<T = unknown>(): T | null {
  const doc = (globalThis as any).document;
  const el = doc?.getElementById?.('rf-bootstrap');
  if (!el || !el.textContent) return null;
  try {
    return JSON.parse(el.textContent) as T;
  } catch {
    return null;
  }
}
