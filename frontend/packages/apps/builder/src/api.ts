import { RequestLayer } from '@rf/request-layer';
import type { Field, Condition } from './store';

const rl = new RequestLayer('/v1');

export interface SaveBody {
  title: string;
  description: string;
  webhook_url: string;
  fields: Field[];
  conditions: Condition[];
}

export const builderApi = {
  create: (body: SaveBody) => rl.post<{ form: { public_id: string } }>('/forms', body),
  update: (publicId: string, body: SaveBody) =>
    rl.put<{ form: { public_id: string } }>(`/forms/${publicId}`, body),
};
