export interface ErrorBeacon {
  kind: 'error' | 'unhandledrejection';
  message: string;
  stack: string;
  url: string;
  line: number;
  col: number;
  page: string;
}

export function errorPayload(e: any, page: string): ErrorBeacon {
  return {
    kind: 'error',
    message: String(e?.message ?? e ?? '').slice(0, 500),
    stack: String(e?.stack ?? '').slice(0, 4000),
    url: String(e?.filename ?? (typeof location !== 'undefined' ? location.href : '')).slice(0, 500),
    line: Number(e?.lineno ?? 0),
    col: Number(e?.colno ?? 0),
    page,
  };
}

export function installErrorReporting(page: string, beacon: (b: ErrorBeacon) => void): void {
  window.addEventListener('error', (ev) => beacon(errorPayload(ev, page)));
  window.addEventListener('unhandledrejection', (ev: PromiseRejectionEvent) => {
    const payload = errorPayload(ev.reason ?? {}, page);
    payload.kind = 'unhandledrejection';
    beacon(payload);
  });
}
