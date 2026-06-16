import { describe, it, expect, beforeEach } from 'vitest';
import { getRouterValue, getAssetPath, getTraceId, getCsrf, getActivePage } from '../index';

describe('router-bridge', () => {
  beforeEach(() => {
    (globalThis as any).window = {
      __rfrouter: {
        ENV: 'test',
        ASSET_PATH: '/build/',
        REVISION: 'abc123',
        TRACE_ID: 'deadbeef',
        CSRF: 'tok',
        BASE_PATH: '/',
        ACTIVE_PAGE: 'dashboard',
      },
    };
  });

  it('reads values with fallback', () => {
    expect(getRouterValue('ENV', 'x')).toBe('test');
    expect(getRouterValue('MISSING', 'fallback')).toBe('fallback');
    expect(getAssetPath()).toBe('/build/');
    expect(getTraceId()).toBe('deadbeef');
    expect(getCsrf()).toBe('tok');
    expect(getActivePage()).toBe('dashboard');
  });

  it('falls back when window is empty', () => {
    (globalThis as any).window = {};
    expect(getAssetPath()).toBe('/');
    expect(getTraceId()).toBe('');
  });
});
