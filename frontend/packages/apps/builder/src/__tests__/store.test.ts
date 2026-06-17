import { describe, it, expect } from 'vitest';
import { builderActions, makeField, newFieldId, store } from '../store';

const { init, addField, updateField, removeField, moveField, addCondition, removeCondition } = builderActions;

function fresh() {
  store.dispatch(
    init({ mode: 'new', publicId: null, title: '', description: '', webhookUrl: '', fields: [], conditions: [] }),
  );
}

describe('builder store', () => {
  it('generates backend-valid field ids', () => {
    expect(newFieldId()).toMatch(/^f_[a-z0-9]{6}$/);
  });

  it('makeField seeds options for option types and max for rating', () => {
    expect(makeField('select').options).toEqual(['Option 1']);
    expect(makeField('rating').max).toBe(5);
    expect(makeField('text').options).toBeUndefined();
  });

  it('adds, updates and removes fields', () => {
    fresh();
    store.dispatch(addField('text'));
    let f = store.getState().builder.fields[0];
    expect(f.type).toBe('text');
    store.dispatch(updateField({ id: f.id, patch: { label: 'Name', required: true } }));
    f = store.getState().builder.fields[0];
    expect(f.label).toBe('Name');
    expect(f.required).toBe(true);
    store.dispatch(removeField(f.id));
    expect(store.getState().builder.fields).toHaveLength(0);
  });

  it('reorders fields with moveField', () => {
    fresh();
    store.dispatch(addField('text'));
    store.dispatch(addField('email'));
    const [a, b] = store.getState().builder.fields.map((f) => f.id);
    store.dispatch(moveField({ id: b, dir: -1 }));
    expect(store.getState().builder.fields.map((f) => f.id)).toEqual([b, a]);
  });

  it('removing a field drops conditions that reference it', () => {
    fresh();
    store.dispatch(addField('text'));
    store.dispatch(addField('email'));
    store.dispatch(addCondition());
    expect(store.getState().builder.conditions).toHaveLength(1);
    const firstFieldId = store.getState().builder.fields[0].id;
    store.dispatch(removeField(firstFieldId));
    expect(store.getState().builder.conditions).toHaveLength(0);
  });

  it('caps conditions at 20 and requires two fields', () => {
    fresh();
    store.dispatch(addField('text'));
    store.dispatch(addCondition()); // only one field → ignored
    expect(store.getState().builder.conditions).toHaveLength(0);
    store.dispatch(addField('email'));
    for (let i = 0; i < 25; i++) store.dispatch(addCondition());
    expect(store.getState().builder.conditions.length).toBe(20);
    store.dispatch(removeCondition(0));
    expect(store.getState().builder.conditions.length).toBe(19);
  });
});
