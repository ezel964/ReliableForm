import { describe, it, expect } from 'vitest';
import { vitalPayload } from '../vitals';
import { errorPayload } from '../errors';

describe('payloads', () => {
  it('vitalPayload shapes a beacon body', () => {
    const p = vitalPayload(
      { name: 'LCP', value: 1820.5, id: 'v3', rating: 'good', navigationType: 'navigate' } as any,
      'dashboard',
    );
    expect(p).toEqual({
      metric: 'LCP',
      value: 1820.5,
      id: 'v3',
      rating: 'good',
      nav: 'navigate',
      page: 'dashboard',
    });
  });

  it('errorPayload truncates the message and tags the page', () => {
    const p = errorPayload(
      { message: 'x'.repeat(600), stack: 's', filename: 'f', lineno: 1, colno: 2 } as any,
      'builder',
    );
    expect(p.page).toBe('builder');
    expect(p.kind).toBe('error');
    expect(p.message.length).toBe(500);
    expect(p.line).toBe(1);
    expect(p.col).toBe(2);
  });
});
