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
