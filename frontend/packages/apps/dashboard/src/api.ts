import { RequestLayer } from '@rf/request-layer';

export interface FormSummary {
  id: number;
  public_id: string;
  title: string;
  is_published: boolean;
  submissions: number;
  webhook_url: string | null;
  created_at: string;
  updated_at: string;
}

export interface Field {
  id: string;
  type: string;
  label: string;
  required?: boolean;
  options?: string[];
  max?: number;
  placeholder?: string;
}

export interface Settings {
  is_published: boolean;
  submission_limit: number | null;
  thankyou_message: string | null;
  autoresponder_enabled: boolean;
}

export interface Submission {
  id: number;
  created_at: string;
  data: Record<string, string | string[]>;
}

const rl = new RequestLayer('/v1');

export const api = {
  listForms: () => rl.get<{ forms: FormSummary[] }>('/forms').then((r) => r.forms),
  deleteForm: (publicId: string) => rl.del(`/forms/${publicId}`),
  getSettings: (publicId: string) =>
    rl.get<{ settings: Settings }>(`/forms/${publicId}/settings`).then((r) => r.settings),
  updateSettings: (publicId: string, body: Partial<Settings>) =>
    rl.put<{ settings: Settings }>(`/forms/${publicId}/settings`, body).then((r) => r.settings),
  listSubmissions: (publicId: string, limit: number, offset: number) =>
    rl.get<{ total: number; submissions: Submission[] }>(
      `/forms/${publicId}/submissions?limit=${limit}&offset=${offset}`,
    ),
  getSubmission: (id: number) =>
    rl
      .get<{ submission: { id: number; created_at: string; data: Record<string, any>; form: { public_id: string; title: string; fields: Field[] } } }>(
        `/submissions/${id}`,
      )
      .then((r) => r.submission),
};
