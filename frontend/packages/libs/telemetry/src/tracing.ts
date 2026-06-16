import { getTraceId } from '@rf/router-bridge';

// Cheap distributed tracing without a browser OTEL SDK (JotForm's approach):
// attach a W3C traceparent to every same-origin API call. The PHP backend
// already adopts traceparent on ingress (lib/Trace.php), so the browser call
// joins the server-side trace.

const hex = (bytes: number): string =>
  Array.from(crypto.getRandomValues(new Uint8Array(bytes)))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');

export function makeTraceparent(): string {
  const seeded = getTraceId();
  const trace = /^[0-9a-f]{32}$/.test(seeded) ? seeded : hex(16);
  return `00-${trace}-${hex(8)}-01`;
}

function sameOrigin(url: string): boolean {
  try {
    return new URL(url, location.href).origin === location.origin;
  } catch {
    return true; // relative URL — same origin
  }
}

export function installTraceHeader(): void {
  const origFetch = globalThis.fetch;
  if (typeof origFetch === 'function') {
    globalThis.fetch = ((input: any, init: any = {}) => {
      const url = typeof input === 'string' ? input : input?.url ?? '';
      if (sameOrigin(url)) {
        const headers = new Headers(init.headers || (typeof input === 'object' ? input.headers : undefined) || {});
        if (!headers.has('traceparent')) headers.set('traceparent', makeTraceparent());
        init = { ...init, headers };
      }
      return origFetch(input, init);
    }) as typeof fetch;
  }

  const XHR = globalThis.XMLHttpRequest;
  if (XHR) {
    const open = XHR.prototype.open as any;
    const send = XHR.prototype.send as any;
    XHR.prototype.open = function (this: XMLHttpRequest, method: string, url: string, ...rest: any[]) {
      (this as any).__rf_url = url;
      return open.call(this, method, url, ...rest);
    } as any;
    XHR.prototype.send = function (this: XMLHttpRequest, body?: any) {
      if (sameOrigin((this as any).__rf_url || '')) {
        try {
          this.setRequestHeader('traceparent', makeTraceparent());
        } catch {
          /* header may be locked; ignore */
        }
      }
      return send.call(this, body);
    } as any;
  }
}
