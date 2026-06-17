import { configureStore, createSlice, type PayloadAction } from '@reduxjs/toolkit';

export const FIELD_TYPES = [
  'text',
  'textarea',
  'email',
  'number',
  'select',
  'radio',
  'checkbox',
  'date',
  'rating',
  'file',
] as const;
export type FieldType = (typeof FIELD_TYPES)[number];

export const OPTION_TYPES: FieldType[] = ['select', 'radio', 'checkbox'];
export const PLACEHOLDER_TYPES: FieldType[] = ['text', 'textarea', 'email', 'number'];

export interface Field {
  id: string;
  type: FieldType;
  label: string;
  required: boolean;
  options?: string[];
  max?: number;
  placeholder?: string;
}

export type ConditionOp = 'equals' | 'not_equals' | 'contains';
export type ConditionAction = 'show' | 'hide';
export interface Condition {
  if: { field: string; op: ConditionOp; value: string };
  then: { action: ConditionAction; target: string };
}

export interface BuilderState {
  mode: 'new' | 'edit';
  publicId: string | null;
  title: string;
  description: string;
  webhookUrl: string;
  fields: Field[];
  conditions: Condition[];
}

// Field ids must match the backend's /^f_[a-z0-9]{6}$/.
export function newFieldId(): string {
  const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  let s = '';
  for (let i = 0; i < 6; i++) s += chars[Math.floor(Math.random() * chars.length)];
  return `f_${s}`;
}

export function makeField(type: FieldType): Field {
  const f: Field = { id: newFieldId(), type, label: defaultLabel(type), required: false };
  if (OPTION_TYPES.includes(type)) f.options = ['Option 1'];
  if (type === 'rating') f.max = 5;
  return f;
}

function defaultLabel(type: FieldType): string {
  return type.charAt(0).toUpperCase() + type.slice(1) + ' field';
}

const initialState: BuilderState = {
  mode: 'new',
  publicId: null,
  title: '',
  description: '',
  webhookUrl: '',
  fields: [],
  conditions: [],
};

const slice = createSlice({
  name: 'builder',
  initialState,
  reducers: {
    init(_state, action: PayloadAction<BuilderState>) {
      return action.payload;
    },
    setMeta(state, action: PayloadAction<Partial<Pick<BuilderState, 'title' | 'description' | 'webhookUrl'>>>) {
      Object.assign(state, action.payload);
    },
    addField(state, action: PayloadAction<FieldType>) {
      if (state.fields.length >= 50) return;
      state.fields.push(makeField(action.payload));
    },
    updateField(state, action: PayloadAction<{ id: string; patch: Partial<Field> }>) {
      const f = state.fields.find((x) => x.id === action.payload.id);
      if (f) Object.assign(f, action.payload.patch);
    },
    removeField(state, action: PayloadAction<string>) {
      state.fields = state.fields.filter((f) => f.id !== action.payload);
      // Drop any condition that references the removed field.
      state.conditions = state.conditions.filter(
        (c) => c.if.field !== action.payload && c.then.target !== action.payload,
      );
    },
    moveField(state, action: PayloadAction<{ id: string; dir: -1 | 1 }>) {
      const i = state.fields.findIndex((f) => f.id === action.payload.id);
      const j = i + action.payload.dir;
      if (i < 0 || j < 0 || j >= state.fields.length) return;
      [state.fields[i], state.fields[j]] = [state.fields[j], state.fields[i]];
    },
    addCondition(state) {
      if (state.conditions.length >= 20 || state.fields.length < 2) return;
      const f = state.fields[0].id;
      const t = state.fields.find((x) => x.id !== f)?.id ?? state.fields[0].id;
      state.conditions.push({ if: { field: f, op: 'equals', value: '' }, then: { action: 'show', target: t } });
    },
    updateCondition(state, action: PayloadAction<{ index: number; patch: Partial<Condition> }>) {
      const c = state.conditions[action.payload.index];
      if (c) state.conditions[action.payload.index] = { ...c, ...action.payload.patch };
    },
    removeCondition(state, action: PayloadAction<number>) {
      state.conditions.splice(action.payload, 1);
    },
  },
});

export const builderActions = slice.actions;

export const store = configureStore({ reducer: { builder: slice.reducer } });
export type RootState = ReturnType<typeof store.getState>;
export type AppDispatch = typeof store.dispatch;
