'use strict';

/**
 * Minimal OpenTelemetry tracing for the Node status service — mirror of
 * lib/Otel.php (the PHP reference implementation). No SDK, no HTTP: span()
 * buffers, flush() appends one JSON line to
 * storage/run/otel-spool/<instance>.jsonl. The otel shipper sidecar
 * (apps/workers/otel_shipper.php) batches spool lines into OTLP/HTTP JSON and
 * POSTs them to OTEL_EXPORTER_OTLP_ENDPOINT/v1/traces — this process never
 * exports directly.
 *
 * Fully disabled (no buffering, no fs) unless OTEL_EXPORTER_OTLP_ENDPOINT is
 * configured (env.js precedence: real env var > .env file).
 *
 * Spool line format (must stay byte-compatible with the PHP writer):
 *   {"service":"reliableform-status","instance":"status","spans":[<OTLP span>, ...]}
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const env = require('./env');

const INSTANCE = process.env.INSTANCE_ID || 'status';
const SPOOL_DIR = path.join(env.PROJECT_ROOT, 'storage', 'run', 'otel-spool');
const SPOOL_FILE = path.join(SPOOL_DIR, INSTANCE + '.jsonl');

const KIND_SERVER = 2;
const KIND_CLIENT = 3;
const KIND_PRODUCER = 4;
const KIND_CONSUMER = 5;

const endpoint = env.get('OTEL_EXPORTER_OTLP_ENDPOINT');
const ENABLED = typeof endpoint === 'string' && endpoint !== '';

const serviceOverride = env.get('OTEL_SERVICE_NAME');
const SERVICE =
  typeof serviceOverride === 'string' && serviceOverride !== ''
    ? serviceOverride
    : 'reliableform-status';

let buffer = [];

function enabled() {
  return ENABLED;
}

// ---------------------------------------------------------------------------
// W3C trace context (same semantics as lib/Trace.php — adopt or start fresh).
// ---------------------------------------------------------------------------

const TRACEPARENT_RE = /^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/;
const ZERO_TRACE_ID = '0'.repeat(32);
const ZERO_SPAN_ID = '0'.repeat(16);

/**
 * Adopt an incoming `traceparent` header value or start a fresh trace.
 * Returns a per-request context { traceId, spanId, parentSpanId|null } —
 * unlike the PHP per-process Trace statics, the context must travel with the
 * request because Node serves requests concurrently in one process.
 */
function startTrace(traceparent) {
  let traceId = null;
  let parentSpanId = null;
  if (typeof traceparent === 'string') {
    const m = TRACEPARENT_RE.exec(traceparent.trim().toLowerCase());
    if (m && m[1] !== ZERO_TRACE_ID && m[2] !== ZERO_SPAN_ID) {
      traceId = m[1];
      parentSpanId = m[2];
    }
  }
  if (traceId === null) {
    traceId = crypto.randomBytes(16).toString('hex');
  }
  return { traceId, spanId: crypto.randomBytes(8).toString('hex'), parentSpanId };
}

// ---------------------------------------------------------------------------
// Spans
// ---------------------------------------------------------------------------

/** OTLP/JSON typed attribute value (int64 carried as string, like PHP). */
function attrValue(v) {
  if (typeof v === 'boolean') return { boolValue: v };
  if (typeof v === 'number') {
    return Number.isInteger(v) ? { intValue: String(v) } : { doubleValue: v };
  }
  return { stringValue: String(v) };
}

/**
 * Record one finished span (OTLP/JSON protobuf mapping: kind as enum number,
 * times as uint64 nanosecond strings, attributes as key/typed-value pairs).
 *
 * @param {{traceId: string, spanId: string, parentSpanId: ?string}} trace from startTrace()
 * @param {string} name e.g. 'GET /api/status'
 * @param {number} startMs epoch milliseconds (performance.timeOrigin + performance.now())
 * @param {number} endMs epoch milliseconds
 * @param {number} kind KIND_* enum number
 * @param {Object<string, string|number|boolean>} attributes
 * @param {boolean} error
 */
function span(trace, name, startMs, endMs, kind, attributes = {}, error = false) {
  if (!ENABLED) return;
  const attrs = [];
  for (const [k, v] of Object.entries(attributes)) {
    attrs.push({ key: k, value: attrValue(v) });
  }
  const s = {
    traceId: trace.traceId,
    spanId: trace.spanId,
    name,
    kind,
    startTimeUnixNano: BigInt(Math.round(startMs * 1e6)).toString(),
    endTimeUnixNano: BigInt(Math.round(endMs * 1e6)).toString(),
    attributes: attrs,
    status: error ? { code: 2 } : { code: 0 },
  };
  if (trace.parentSpanId) {
    s.parentSpanId = trace.parentSpanId; // appended last, like the PHP writer
  }
  buffer.push(s);
}

/**
 * Append buffered spans to the spool as ONE JSON line. Called after every
 * response. Never throws and swallows all fs errors — telemetry must not
 * break the request.
 */
function flush() {
  if (!ENABLED || buffer.length === 0) return;
  const spans = buffer;
  buffer = [];
  try {
    const line = JSON.stringify({ service: SERVICE, instance: INSTANCE, spans });
    fs.mkdir(SPOOL_DIR, { recursive: true }, () => {
      fs.appendFile(SPOOL_FILE, line + '\n', () => {});
    });
  } catch {
    // swallow — see contract
  }
}

module.exports = {
  enabled,
  startTrace,
  span,
  flush,
  KIND_SERVER,
  KIND_CLIENT,
  KIND_PRODUCER,
  KIND_CONSUMER,
};
