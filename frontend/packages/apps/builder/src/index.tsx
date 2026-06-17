import { createRoot } from 'react-dom/client';
import { Provider } from 'react-redux';
import { getBootstrap, getCsrf } from '@rf/router-bridge';
import { store, builderActions, type BuilderState, type Field, type Condition } from './store';
import { Builder } from './Builder';

interface Boot {
  mode?: 'new' | 'edit';
  form?: {
    public_id: string;
    title: string;
    description: string;
    webhook_url: string;
    fields: Field[];
    conditions: Condition[];
  } | null;
}

const boot = getBootstrap<Boot>() ?? {};
const initial: BuilderState =
  boot.mode === 'edit' && boot.form
    ? {
        mode: 'edit',
        publicId: boot.form.public_id,
        title: boot.form.title ?? '',
        description: boot.form.description ?? '',
        webhookUrl: boot.form.webhook_url ?? '',
        fields: (boot.form.fields ?? []) as Field[],
        conditions: (boot.form.conditions ?? []) as Condition[],
      }
    : {
        mode: 'new',
        publicId: null,
        title: '',
        description: '',
        webhookUrl: '',
        fields: [],
        conditions: [],
      };

store.dispatch(builderActions.init(initial));

const el = document.getElementById('root');
if (el) {
  createRoot(el).render(
    <Provider store={store}>
      <Builder csrf={getCsrf()} />
    </Provider>,
  );
}
