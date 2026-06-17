import { useEffect, useState } from 'react';
import { Routes, Route, Link, useParams, useNavigate, Navigate } from 'react-router-dom';
import { getBootstrap, getCsrf, getRouterValue } from '@rf/router-bridge';
import { api, type FormSummary, type Settings, type Submission, type Field } from './api';

interface BootUser {
  user?: { id: number; email: string; name: string };
}

function useForms() {
  const [forms, setForms] = useState<FormSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api.listForms().then(setForms).catch((e) => setError(e.message ?? 'Failed to load'));
  }, []);
  return { forms, error, setForms };
}

function Header() {
  const boot = getBootstrap<BootUser>();
  const name = boot?.user?.name ?? '';
  return (
    <header className="site-header">
      <div className="container header-bar">
        <Link className="brand" to="/dashboard">
          Reliable<span>Form</span>
        </Link>
        <nav className="site-nav" aria-label="Main">
          <Link className="nav-link" to="/dashboard">
            Dashboard
          </Link>
          <a className="btn btn-primary btn-small" href="/forms/new">
            + New form
          </a>
          {name && <span className="nav-user">{name}</span>}
          <form method="post" action="/logout" className="inline-form">
            <input type="hidden" name="_token" value={getCsrf()} />
            <button type="submit" className="link-button">
              Log out
            </button>
          </form>
        </nav>
      </div>
    </header>
  );
}

function FormsList() {
  const { forms, error, setForms } = useForms();
  const apiBase = getRouterValue('BASE_PATH', '/');
  void apiBase;

  const onDelete = async (f: FormSummary) => {
    if (!confirm(`Delete "${f.title}" and all its submissions? This cannot be undone.`)) return;
    await api.deleteForm(f.public_id);
    setForms((forms ?? []).filter((x) => x.id !== f.id));
  };

  if (error) return <p className="flash flash-error">{error}</p>;
  if (!forms) return <p className="subtitle">Loading forms…</p>;

  return (
    <div>
      <div className="page-head">
        <h1>Your forms</h1>
        <a className="btn btn-primary" href="/forms/new">
          + New form
        </a>
      </div>
      {forms.length === 0 ? (
        <p className="subtitle">No forms yet. Create your first one.</p>
      ) : (
        <table className="table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Status</th>
              <th>Submissions</th>
              <th>Public link</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {forms.map((f) => (
              <tr key={f.id}>
                <td>
                  <a href={`/forms/${f.id}/edit`}>{f.title}</a>
                </td>
                <td>
                  <span className={`badge ${f.is_published ? 'badge-done' : 'badge-pending'}`}>
                    {f.is_published ? 'published' : 'draft'}
                  </span>
                </td>
                <td>
                  <Link to={`/forms/${f.id}/submissions`}>{f.submissions}</Link>
                </td>
                <td>
                  <button
                    className="link-button"
                    onClick={() => navigator.clipboard?.writeText(`${location.origin}/f/${f.public_id}`)}
                  >
                    Copy link
                  </button>
                </td>
                <td className="row-actions">
                  <a href={`/forms/${f.id}/edit`}>Edit</a>
                  <Link to={`/forms/${f.id}/settings`}>Settings</Link>
                  <button className="link-button danger" onClick={() => onDelete(f)}>
                    Delete
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

// Resolve a numeric form id (from the URL) to its public_id via the forms list.
function useFormById(id: number) {
  const [form, setForm] = useState<FormSummary | null | undefined>(undefined);
  useEffect(() => {
    api
      .listForms()
      .then((forms) => setForm(forms.find((f) => f.id === id) ?? null))
      .catch(() => setForm(null));
  }, [id]);
  return form;
}

function Submissions() {
  const { id } = useParams();
  const formId = Number(id);
  const form = useFormById(formId);
  const [page, setPage] = useState(0);
  const [data, setData] = useState<{ total: number; submissions: Submission[] } | null>(null);
  const perPage = 25;

  useEffect(() => {
    if (!form) return;
    api.listSubmissions(form.public_id, perPage, page * perPage).then(setData);
  }, [form, page]);

  if (form === undefined) return <p className="subtitle">Loading…</p>;
  if (form === null) return <p className="flash flash-error">Form not found.</p>;

  return (
    <div>
      <div className="page-head">
        <h1>{form.title} — submissions</h1>
        <Link className="btn" to="/dashboard">
          ← Back
        </Link>
      </div>
      {!data ? (
        <p className="subtitle">Loading submissions…</p>
      ) : data.submissions.length === 0 ? (
        <p className="subtitle">No submissions yet.</p>
      ) : (
        <>
          <table className="table">
            <thead>
              <tr>
                <th>When</th>
                <th>Preview</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {data.submissions.map((s) => (
                <tr key={s.id}>
                  <td>{s.created_at}</td>
                  <td>{preview(s.data)}</td>
                  <td>
                    <Link to={`/submissions/${s.id}`}>View</Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="pagination">
            <button className="btn btn-small" disabled={page === 0} onClick={() => setPage((p) => p - 1)}>
              ← Prev
            </button>
            <span className="subtitle">
              {page * perPage + 1}–{Math.min((page + 1) * perPage, data.total)} of {data.total}
            </span>
            <button
              className="btn btn-small"
              disabled={(page + 1) * perPage >= data.total}
              onClick={() => setPage((p) => p + 1)}
            >
              Next →
            </button>
          </div>
        </>
      )}
    </div>
  );
}

function preview(data: Record<string, string | string[]>): string {
  const parts: string[] = [];
  for (const v of Object.values(data)) {
    const s = Array.isArray(v) ? v.join(', ') : v;
    if (s && s.trim() !== '') parts.push(s.length > 48 ? s.slice(0, 48) + '…' : s);
    if (parts.length >= 2) break;
  }
  return parts.join(' · ');
}

function SubmissionDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [sub, setSub] = useState<Awaited<ReturnType<typeof api.getSubmission>> | null | undefined>(undefined);

  useEffect(() => {
    api
      .getSubmission(Number(id))
      .then(setSub)
      .catch(() => setSub(null));
  }, [id]);

  if (sub === undefined) return <p className="subtitle">Loading…</p>;
  if (sub === null) return <p className="flash flash-error">Submission not found.</p>;

  const labelFor = (fid: string) => sub.form.fields.find((f: Field) => f.id === fid)?.label ?? fid;

  return (
    <div>
      <div className="page-head">
        <h1>Submission #{sub.id}</h1>
        <button className="btn" onClick={() => navigate(-1)}>
          ← Back
        </button>
      </div>
      <p className="subtitle">
        {sub.form.title} · {sub.created_at}
      </p>
      <table className="table">
        <tbody>
          {Object.entries(sub.data).map(([fid, value]) => (
            <tr key={fid}>
              <th style={{ width: '30%' }}>{labelFor(fid)}</th>
              <td>{Array.isArray(value) ? value.join(', ') : String(value)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <p>
        <a className="btn btn-small" href={`/submissions/${sub.id}/pdf`}>
          Download PDF
        </a>
      </p>
    </div>
  );
}

function SettingsPage() {
  const { id } = useParams();
  const form = useFormById(Number(id));
  const [settings, setSettings] = useState<Settings | null>(null);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!form) return;
    api.getSettings(form.public_id).then(setSettings);
  }, [form]);

  if (form === undefined) return <p className="subtitle">Loading…</p>;
  if (form === null) return <p className="flash flash-error">Form not found.</p>;
  if (!settings) return <p className="subtitle">Loading settings…</p>;

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setSaved(false);
    try {
      const next = await api.updateSettings(form.public_id, settings);
      setSettings(next);
      setSaved(true);
    } catch (err: any) {
      setError(err.message ?? 'Save failed');
    }
  };

  return (
    <div>
      <div className="page-head">
        <h1>{form.title} — settings</h1>
        <Link className="btn" to="/dashboard">
          ← Back
        </Link>
      </div>
      {saved && <p className="flash flash-success">Settings saved.</p>}
      {error && <p className="flash flash-error">{error}</p>}
      <form onSubmit={save} className="stack">
        <label className="switch-row">
          <input
            type="checkbox"
            checked={settings.is_published}
            onChange={(e) => setSettings({ ...settings, is_published: e.target.checked })}
          />
          Published (accepting responses)
        </label>
        <label className="field">
          <span>Submission limit (blank = unlimited)</span>
          <input
            className="input"
            type="number"
            min={1}
            value={settings.submission_limit ?? ''}
            onChange={(e) =>
              setSettings({
                ...settings,
                submission_limit: e.target.value === '' ? null : Number(e.target.value),
              })
            }
          />
        </label>
        <label className="field">
          <span>Thank-you message</span>
          <textarea
            className="input"
            value={settings.thankyou_message ?? ''}
            maxLength={500}
            onChange={(e) => setSettings({ ...settings, thankyou_message: e.target.value || null })}
          />
        </label>
        <label className="switch-row">
          <input
            type="checkbox"
            checked={settings.autoresponder_enabled}
            onChange={(e) => setSettings({ ...settings, autoresponder_enabled: e.target.checked })}
          />
          Send autoresponder email to the submitter
        </label>
        <div>
          <button className="btn btn-primary" type="submit">
            Save settings
          </button>
        </div>
      </form>
    </div>
  );
}

export function App() {
  return (
    <>
      <Header />
      <main className="container main" id="main">
        <Routes>
          <Route path="/dashboard" element={<FormsList />} />
          <Route path="/forms/:id/submissions" element={<Submissions />} />
          <Route path="/forms/:id/settings" element={<SettingsPage />} />
          <Route path="/submissions/:id" element={<SubmissionDetail />} />
          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
      </main>
    </>
  );
}
