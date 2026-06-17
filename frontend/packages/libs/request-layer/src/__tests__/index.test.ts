import { describe, it, expect, vi, beforeEach } from 'vitest';
import { RequestLayer } from '../index';

beforeEach(() => {
  (globalThis as any).window = { __rfrouter: { CSRF: 'csrf-token' } };
});

describe('request-layer', () => {
  it('unwraps the { ok:true, ... } success envelope', async () => {
    const adapter = vi.fn(async () => ({ status: 200, data: { ok: true, forms: [{ id: 1 }] } }));
    const r = new RequestLayer('/v1', { adapter });
    const out = await r.get<{ forms: unknown[] }>('/forms');
    expect(out.forms).toEqual([{ id: 1 }]);
  });

  it('rejects with a mapped ApiError on { ok:false }', async () => {
    const adapter = vi.fn(async () => ({
      status: 422,
      data: {
        ok: false,
        error: { code: 'validation_failed', message: 'bad', trace_id: 't', fields: { a: 'req' } },
      },
    }));
    const r = new RequestLayer('/v1', { adapter });
    await expect(r.post('/forms', {})).rejects.toMatchObject({
      code: 'validation_failed',
      traceId: 't',
      fields: { a: 'req' },
      status: 422,
    });
  });

  it('sends the CSRF header on mutating calls but not GET (cookie mode)', async () => {
    const seen: Record<string, string>[] = [];
    const adapter = vi.fn(async (cfg: any) => {
      seen.push(cfg.headers);
      return { status: 200, data: { ok: true } };
    });
    const r = new RequestLayer('/v1', { adapter, apiKey: '' });
    await r.get('/me');
    await r.post('/forms', {});
    expect(seen[0]['X-CSRF-Token']).toBeUndefined();
    expect(seen[1]['X-CSRF-Token']).toBe('csrf-token');
    expect(seen[1]['Authorization']).toBeUndefined();
  });

  it('sends Bearer auth and no CSRF when an apiKey is set', async () => {
    const seen: Record<string, string>[] = [];
    const adapter = vi.fn(async (cfg: any) => {
      seen.push(cfg.headers);
      return { status: 200, data: { ok: true } };
    });
    const r = new RequestLayer('/v1', { adapter, apiKey: 'deadbeef' });
    await r.get('/forms');
    await r.post('/forms', {});
    expect(seen[0]['Authorization']).toBe('Bearer deadbeef');
    expect(seen[1]['Authorization']).toBe('Bearer deadbeef');
    expect(seen[1]['X-CSRF-Token']).toBeUndefined();
  });
});
