import { describe, it, expect, vi, beforeEach } from 'vitest';
import { installTraceHeader, makeTraceparent } from '../tracing';

describe('tracing', () => {
  beforeEach(() => {
    (globalThis as any).window = { __rfrouter: { TRACE_ID: '' } };
    (globalThis as any).location = { origin: 'http://localhost', href: 'http://localhost/' };
  });

  it('makeTraceparent yields a valid W3C header', () => {
    expect(makeTraceparent()).toMatch(/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/);
  });

  it('reuses a seeded 32-hex trace id from router-bridge', () => {
    (globalThis as any).window.__rfrouter.TRACE_ID = 'a'.repeat(32);
    expect(makeTraceparent()).toMatch(new RegExp(`^00-${'a'.repeat(32)}-[0-9a-f]{16}-01$`));
  });

  it('patches fetch to add traceparent for same-origin requests', async () => {
    const calls: any[] = [];
    (globalThis as any).fetch = vi.fn(async (_u: string, init: any) => {
      calls.push(init);
      return { ok: true };
    });
    installTraceHeader();
    await (globalThis as any).fetch('http://localhost/v1/me', {});
    const h = new Headers(calls[0].headers);
    expect(h.get('traceparent')).toMatch(/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/);
  });

  it('does not add traceparent for cross-origin requests', async () => {
    const calls: any[] = [];
    (globalThis as any).fetch = vi.fn(async (_u: string, init: any) => {
      calls.push(init);
      return { ok: true };
    });
    installTraceHeader();
    await (globalThis as any).fetch('https://evil.example.com/x', {});
    const h = new Headers(calls[0].headers || {});
    expect(h.get('traceparent')).toBeNull();
  });
});
