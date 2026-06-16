import { installTraceHeader } from './tracing';
import { reportVitals, type VitalBeacon } from './vitals';
import { installErrorReporting, type ErrorBeacon } from './errors';

export { installTraceHeader, makeTraceparent } from './tracing';
export { reportVitals, vitalPayload, type VitalBeacon } from './vitals';
export { installErrorReporting, errorPayload, type ErrorBeacon } from './errors';

function beaconTo(path: string) {
  return (body: VitalBeacon | ErrorBeacon) => {
    const data = JSON.stringify(body);
    if (typeof navigator !== 'undefined' && navigator.sendBeacon) {
      navigator.sendBeacon(path, data);
    } else {
      fetch(path, {
        method: 'POST',
        body: data,
        headers: { 'Content-Type': 'application/json' },
        keepalive: true,
      }).catch(() => {
        /* beacon best-effort */
      });
    }
  };
}

export interface TelemetryOptions {
  page: string;
  rumPath?: string;
  clientlogPath?: string;
}

/**
 * One call to wire up frontend SRE: traceparent on API calls, web-vitals RUM
 * beacons, and JS error reporting. Mirrors JotForm's per-app tracking init.
 */
export function initTelemetry(opts: TelemetryOptions): void {
  const rum = opts.rumPath ?? '/v1/rum';
  const clog = opts.clientlogPath ?? '/v1/clientlog';
  installTraceHeader();
  installErrorReporting(opts.page, beaconTo(clog));
  reportVitals(opts.page, beaconTo(rum));
}
