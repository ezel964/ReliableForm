// Mirrors ReliableForm's /v1 envelope (see docs/API.md):
//   success: { ok: true, ...payload }
//   error:   { ok: false, error: { code, message, trace_id, fields? } }

export interface ApiError {
  code: string;
  message: string;
  traceId: string;
  status: number;
  fields?: Record<string, string>;
}

export interface AdapterResponse {
  status: number;
  data: any;
}

export type Adapter = (cfg: {
  method: string;
  url: string;
  data?: unknown;
  headers: Record<string, string>;
}) => Promise<AdapterResponse>;
