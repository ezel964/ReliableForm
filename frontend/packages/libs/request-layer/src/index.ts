import axios from 'axios';
import { getCsrf } from '@rf/router-bridge';
import type { Adapter, ApiError } from './types';

export type { ApiError } from './types';

/**
 * Thin HTTP client around the /v1 API. Sends the session cookie
 * (withCredentials) and the CSRF token header on mutating calls, and unwraps
 * the { ok, ... } envelope — rejecting with a typed ApiError on { ok:false }.
 */
export class RequestLayer {
  private adapter: Adapter;

  constructor(private baseURL: string, opts?: { adapter?: Adapter }) {
    this.adapter = opts?.adapter ?? this.axiosAdapter();
  }

  private axiosAdapter(): Adapter {
    const inst = axios.create({
      baseURL: this.baseURL,
      withCredentials: true,
      validateStatus: () => true,
    });
    return async (cfg) => {
      const res = await inst.request({
        method: cfg.method,
        url: cfg.url,
        data: cfg.data,
        headers: cfg.headers,
      });
      return { status: res.status, data: res.data };
    };
  }

  private headers(method: string): Record<string, string> {
    const h: Record<string, string> = { 'Content-Type': 'application/json' };
    if (method !== 'GET') h['X-CSRF-Token'] = getCsrf();
    return h;
  }

  private unwrap(res: AdapterResponseLike): any {
    const d = res.data;
    if (d && d.ok === false) {
      const err: ApiError = {
        code: d.error?.code ?? 'internal_error',
        message: d.error?.message ?? 'Request failed',
        traceId: d.error?.trace_id ?? '',
        status: res.status,
        fields: d.error?.fields,
      };
      throw err;
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

interface AdapterResponseLike {
  status: number;
  data: any;
}
