import { useState } from 'react';
import { useSelector, useDispatch } from 'react-redux';
import {
  builderActions,
  FIELD_TYPES,
  OPTION_TYPES,
  PLACEHOLDER_TYPES,
  type RootState,
  type AppDispatch,
  type Field,
  type FieldType,
  type ConditionOp,
  type ConditionAction,
} from './store';
import { builderApi } from './api';

const OPS: ConditionOp[] = ['equals', 'not_equals', 'contains'];
const ACTIONS: ConditionAction[] = ['show', 'hide'];

export function Builder({ csrf }: { csrf: string }) {
  const state = useSelector((s: RootState) => s.builder);
  const dispatch = useDispatch<AppDispatch>();
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const save = async () => {
    setError(null);
    setSaving(true);
    const body = {
      title: state.title,
      description: state.description,
      webhook_url: state.webhookUrl,
      fields: state.fields,
      conditions: state.conditions,
    };
    try {
      if (state.mode === 'edit' && state.publicId) {
        await builderApi.update(state.publicId, body);
      } else {
        await builderApi.create(body);
      }
      location.href = '/dashboard';
    } catch (e: any) {
      setError(e.message ?? 'Save failed');
      setSaving(false);
    }
  };

  return (
    <>
      <header className="site-header">
        <div className="container header-bar">
          <a className="brand" href="/dashboard">
            Reliable<span>Form</span>
          </a>
          <nav className="site-nav">
            <a className="nav-link" href="/dashboard">
              Dashboard
            </a>
            <form method="post" action="/logout" className="inline-form">
              <input type="hidden" name="_token" value={csrf} />
              <button type="submit" className="link-button">
                Log out
              </button>
            </form>
          </nav>
        </div>
      </header>
      <main className="container main" id="main">
        <div className="page-head">
          <h1>{state.mode === 'edit' ? 'Edit form' : 'New form'}</h1>
          <button className="btn btn-primary" onClick={save} disabled={saving}>
            {saving ? 'Saving…' : 'Save form'}
          </button>
        </div>
        {error && <p className="flash flash-error">{error}</p>}

        <section className="stack">
          <label className="field">
            <span>Form title</span>
            <input
              className="input"
              value={state.title}
              maxLength={200}
              onChange={(e) => dispatch(builderActions.setMeta({ title: e.target.value }))}
            />
          </label>
          <label className="field">
            <span>Description (optional)</span>
            <textarea
              className="input"
              value={state.description}
              maxLength={2000}
              onChange={(e) => dispatch(builderActions.setMeta({ description: e.target.value }))}
            />
          </label>
          <label className="field">
            <span>Webhook URL (optional)</span>
            <input
              className="input"
              value={state.webhookUrl}
              placeholder="https://example.com/hook"
              onChange={(e) => dispatch(builderActions.setMeta({ webhookUrl: e.target.value }))}
            />
          </label>
        </section>

        <h2>Fields</h2>
        <div className="palette">
          {FIELD_TYPES.map((t) => (
            <button key={t} className="btn btn-small" onClick={() => dispatch(builderActions.addField(t))}>
              + {t}
            </button>
          ))}
        </div>

        {state.fields.length === 0 && <p className="subtitle">Add a field to get started.</p>}
        <div className="stack">
          {state.fields.map((f, i) => (
            <FieldCard key={f.id} field={f} index={i} count={state.fields.length} />
          ))}
        </div>

        <h2>Conditional logic</h2>
        <Conditions />
      </main>
    </>
  );
}

function FieldCard({ field, index, count }: { field: Field; index: number; count: number }) {
  const dispatch = useDispatch<AppDispatch>();
  const patch = (p: Partial<Field>) => dispatch(builderActions.updateField({ id: field.id, patch: p }));

  return (
    <div className="builder-card">
      <div className="builder-card-head">
        <strong>{field.type}</strong>
        <div className="row-actions">
          <button className="link-button" disabled={index === 0} onClick={() => dispatch(builderActions.moveField({ id: field.id, dir: -1 }))}>
            ↑
          </button>
          <button
            className="link-button"
            disabled={index === count - 1}
            onClick={() => dispatch(builderActions.moveField({ id: field.id, dir: 1 }))}
          >
            ↓
          </button>
          <button className="link-button danger" onClick={() => dispatch(builderActions.removeField(field.id))}>
            Remove
          </button>
        </div>
      </div>
      <label className="field">
        <span>Label</span>
        <input className="input" value={field.label} maxLength={200} onChange={(e) => patch({ label: e.target.value })} />
      </label>
      <label className="switch-row">
        <input type="checkbox" checked={field.required} onChange={(e) => patch({ required: e.target.checked })} /> Required
      </label>
      {PLACEHOLDER_TYPES.includes(field.type) && (
        <label className="field">
          <span>Placeholder</span>
          <input
            className="input"
            value={field.placeholder ?? ''}
            maxLength={200}
            onChange={(e) => patch({ placeholder: e.target.value })}
          />
        </label>
      )}
      {field.type === 'rating' && (
        <label className="field">
          <span>Max stars (1–10)</span>
          <input
            className="input"
            type="number"
            min={1}
            max={10}
            value={field.max ?? 5}
            onChange={(e) => patch({ max: Number(e.target.value) })}
          />
        </label>
      )}
      {OPTION_TYPES.includes(field.type) && <OptionsEditor field={field} />}
    </div>
  );
}

function OptionsEditor({ field }: { field: Field }) {
  const dispatch = useDispatch<AppDispatch>();
  const options = field.options ?? [];
  const setOptions = (next: string[]) => dispatch(builderActions.updateField({ id: field.id, patch: { options: next } }));

  return (
    <div className="field">
      <span>Options</span>
      {options.map((opt, i) => (
        <div key={i} className="option-row">
          <input
            className="input"
            value={opt}
            maxLength={100}
            onChange={(e) => setOptions(options.map((o, j) => (j === i ? e.target.value : o)))}
          />
          <button
            className="link-button danger"
            disabled={options.length <= 1}
            onClick={() => setOptions(options.filter((_, j) => j !== i))}
          >
            ✕
          </button>
        </div>
      ))}
      <button className="btn btn-small" disabled={options.length >= 20} onClick={() => setOptions([...options, `Option ${options.length + 1}`])}>
        + Add option
      </button>
    </div>
  );
}

function Conditions() {
  const state = useSelector((s: RootState) => s.builder);
  const dispatch = useDispatch<AppDispatch>();
  const { fields, conditions } = state;

  if (fields.length < 2) {
    return <p className="subtitle">Add at least two fields to create logic rules.</p>;
  }

  return (
    <div className="stack">
      {conditions.map((c, i) => (
        <div key={i} className="builder-card condition-row">
          <span>If</span>
          <select
            className="input"
            value={c.if.field}
            onChange={(e) => dispatch(builderActions.updateCondition({ index: i, patch: { if: { ...c.if, field: e.target.value } } }))}
          >
            {fields.map((f) => (
              <option key={f.id} value={f.id}>
                {f.label}
              </option>
            ))}
          </select>
          <select
            className="input"
            value={c.if.op}
            onChange={(e) =>
              dispatch(builderActions.updateCondition({ index: i, patch: { if: { ...c.if, op: e.target.value as ConditionOp } } }))
            }
          >
            {OPS.map((o) => (
              <option key={o} value={o}>
                {o.replace('_', ' ')}
              </option>
            ))}
          </select>
          <input
            className="input"
            value={c.if.value}
            maxLength={200}
            placeholder="value"
            onChange={(e) => dispatch(builderActions.updateCondition({ index: i, patch: { if: { ...c.if, value: e.target.value } } }))}
          />
          <select
            className="input"
            value={c.then.action}
            onChange={(e) =>
              dispatch(
                builderActions.updateCondition({ index: i, patch: { then: { ...c.then, action: e.target.value as ConditionAction } } }),
              )
            }
          >
            {ACTIONS.map((a) => (
              <option key={a} value={a}>
                {a}
              </option>
            ))}
          </select>
          <select
            className="input"
            value={c.then.target}
            onChange={(e) =>
              dispatch(builderActions.updateCondition({ index: i, patch: { then: { ...c.then, target: e.target.value } } }))
            }
          >
            {fields.map((f) => (
              <option key={f.id} value={f.id}>
                {f.label}
              </option>
            ))}
          </select>
          <button className="link-button danger" onClick={() => dispatch(builderActions.removeCondition(i))}>
            ✕
          </button>
        </div>
      ))}
      <button className="btn btn-small" disabled={conditions.length >= 20} onClick={() => dispatch(builderActions.addCondition())}>
        + Add rule
      </button>
    </div>
  );
}
