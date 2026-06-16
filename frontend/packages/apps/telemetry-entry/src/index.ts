import { initTelemetry } from '@rf/telemetry';
import { getActivePage } from '@rf/router-bridge';

// Page name comes from <body data-rf-page="..."> (set by PHP views) or, on SPA
// shells, from window.__rfrouter.ACTIVE_PAGE.
const page =
  (typeof document !== 'undefined' && document.body?.getAttribute('data-rf-page')) ||
  getActivePage();

initTelemetry({ page });
