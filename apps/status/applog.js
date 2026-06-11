'use strict';

/**
 * Structured JSON event log — mirror of lib/AppLog.php. One line per request,
 * appended to storage/logs/app-<instance>.jsonl (single-line append keeps
 * concurrent writers from interleaving). Gated by APPLOG_ENABLED (default on,
 * '0' disables — the guard short-circuits so the off cost is zero). Never
 * throws.
 */

const fs = require('fs');
const path = require('path');
const env = require('./env');

const INSTANCE = process.env.INSTANCE_ID || 'status';
const LOG_FILE = path.join(env.PROJECT_ROOT, 'storage', 'logs', 'app-' + INSTANCE + '.jsonl');

const ENABLED = env.get('APPLOG_ENABLED', '1') !== '0';

function enabled() {
  return ENABLED;
}

/**
 * Write one event line: {"ts","instance","event","trace_id", ...fields}.
 * Matches the PHP AppLog shape: UTC ISO-8601 ts with milliseconds, string
 * fields capped at 512 chars (atomic-append invariant: lines far below 8KB).
 *
 * @param {string} eventName e.g. 'http_request'
 * @param {string} traceId current 32-hex trace id
 * @param {Object<string, *>} fields
 */
function event(eventName, traceId, fields = {}) {
  if (!ENABLED) return;
  try {
    const out = {
      ts: new Date().toISOString(), // e.g. 2026-06-11T09:00:00.123Z — same shape as PHP
      instance: INSTANCE,
      event: eventName,
      trace_id: traceId,
    };
    for (const [k, v] of Object.entries(fields)) {
      out[k] = typeof v === 'string' && v.length > 512 ? v.slice(0, 512) + '…' : v;
    }
    fs.appendFile(LOG_FILE, JSON.stringify(out) + '\n', () => {});
  } catch {
    // observability must never break the request
  }
}

module.exports = { enabled, event };
