'use strict';

/**
 * ReliableForm status & analytics service.
 * Node 18+ core APIs only, plus mysql2 (degrades gracefully when absent).
 * Endpoints: GET /healthz, /api/status, /api/stats, /status (HTML dashboard).
 */

const http = require('http');
const dgram = require('dgram');
const fs = require('fs');
const path = require('path');
const { performance } = require('perf_hooks');
const env = require('./env');
const { RedisClient } = require('./redis');
const otel = require('./otel');
const applog = require('./applog');

const INSTANCE = process.env.INSTANCE_ID || 'status';
const STATUS_PORT = env.getInt('STATUS_PORT');
const LB_PORT = env.getInt('LB_PORT');
const STATSD_HOST = env.get('STATSD_HOST');
const STATSD_PORT = env.getInt('STATSD_PORT');

const PROBE_TIMEOUT_MS = 2000;
const FPM_STATUS_TIMEOUT_MS = 1000;
const QUEUE_WARN_DEPTH = 100;

// launch.sh records the resolved WEB_RUNTIME (fpm|cli) here on every start —
// the only reliable way to know what the RUNNING web tier was started with.
const WEB_RUNTIME_MARKER = path.join(env.PROJECT_ROOT, 'storage', 'run', 'web-runtime');

// ---------------------------------------------------------------------------
// MySQL — optional at runtime: the service must boot without npm install.
// ---------------------------------------------------------------------------

let mysql = null;
let mysqlLoadError = null;
try {
  // eslint-disable-next-line global-require
  mysql = require('mysql2/promise');
} catch {
  mysqlLoadError = 'mysql2 not installed — run setup.sh';
}

let pool = null;
function getPool() {
  if (pool === null && mysql !== null) {
    pool = mysql.createPool({
      host: env.get('DB_HOST'),
      port: env.getInt('DB_PORT'),
      user: env.get('DB_USER'),
      password: env.get('DB_PASS'),
      database: env.get('DB_NAME'),
      connectionLimit: 4,
      connectTimeout: 2000,
      waitForConnections: true,
    });
  }
  return pool;
}

// ---------------------------------------------------------------------------
// Redis — tiny inline RESP2 client (see redis.js).
// ---------------------------------------------------------------------------

const redis = new RedisClient(env.get('REDIS_HOST'), env.getInt('REDIS_PORT'), PROBE_TIMEOUT_MS);

// ---------------------------------------------------------------------------
// statsd — fire-and-forget UDP, "reliableform." prefix like lib/Metrics.php.
// ---------------------------------------------------------------------------

const statsdSock = dgram.createSocket('udp4');
statsdSock.on('error', () => {});
statsdSock.unref();

function statsdSend(payload) {
  try {
    statsdSock.send(Buffer.from('reliableform.' + payload), STATSD_PORT, STATSD_HOST, () => {});
  } catch {
    /* never throws upward */
  }
}
const metricIncrement = (m, v = 1) => statsdSend(`${m}:${v}|c`);
const metricTiming = (m, ms) => statsdSend(`${m}:${ms.toFixed(3)}|ms`);

// ---------------------------------------------------------------------------
// Probe helpers
// ---------------------------------------------------------------------------

function withTimeout(promise, ms, label = 'timeout') {
  let timer;
  const gate = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(label)), ms);
  });
  return Promise.race([promise, gate]).finally(() => clearTimeout(timer));
}

function errDetail(err) {
  if (err && err.code === 'ECONNREFUSED') return 'connection refused';
  return (err && err.message) || 'unknown error';
}

function elapsedMs(startNs) {
  return Math.round(Number(process.hrtime.bigint() - startNs) / 1e5) / 10;
}

/** Wrap a probe: individually timed, all failures caught. */
async function timedProbe(name, fn) {
  const start = process.hrtime.bigint();
  try {
    const r = await fn();
    return { name, ok: r.ok, latency_ms: elapsedMs(start), detail: r.detail };
  } catch (err) {
    return { name, ok: false, latency_ms: elapsedMs(start), detail: errDetail(err) };
  }
}

/** GET http://127.0.0.1:<port><path> with a hard timeout. Never rejects. */
function httpProbe(port, probePath = '/healthz') {
  return new Promise((resolve) => {
    let settled = false;
    const done = (result) => {
      if (!settled) {
        settled = true;
        resolve(result);
      }
    };
    const req = http.get(
      { host: '127.0.0.1', port, path: probePath, timeout: PROBE_TIMEOUT_MS },
      (res) => {
        res.resume();
        res.on('end', () => {
          done({ ok: res.statusCode === 200, detail: 'HTTP ' + res.statusCode });
        });
      }
    );
    req.on('timeout', () => req.destroy(new Error('timeout')));
    req.on('error', (err) => done({ ok: false, detail: errDetail(err) }));
  });
}

/** Resolved web runtime as recorded by launch.sh ('' when unknown). */
function webRuntime() {
  try {
    return fs.readFileSync(WEB_RUNTIME_MARKER, 'utf8').trim();
  } catch {
    return '';
  }
}

/**
 * Best-effort pool detail from the LB's /__pool/<name>/fpm-status?json route
 * (fpm mode only). One extra localhost request with its own short timeout;
 * any failure — timeout, non-200, bad JSON — just means no extra detail.
 * It must never fail the health probe itself.
 */
function fpmPoolDetail(name) {
  return new Promise((resolve) => {
    let settled = false;
    const done = (suffix) => {
      if (!settled) {
        settled = true;
        resolve(suffix);
      }
    };
    const req = http.get(
      {
        host: '127.0.0.1',
        port: LB_PORT,
        path: `/__pool/${name}/fpm-status?json`,
        timeout: FPM_STATUS_TIMEOUT_MS,
      },
      (res) => {
        if (res.statusCode !== 200) {
          res.resume();
          res.on('end', () => done(''));
          return;
        }
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => {
          try {
            const st = JSON.parse(Buffer.concat(chunks).toString('utf8'));
            const active = st['active processes'];
            const queue = st['listen queue'];
            if (active === undefined || queue === undefined) return done('');
            done(` · fpm active=${active} queue=${queue}`);
          } catch {
            done('');
          }
        });
      }
    );
    req.on('timeout', () => req.destroy(new Error('timeout')));
    req.on('error', () => done(''));
  });
}

/**
 * web1/web2 probe. Direct :PORT probes die under php-fpm (FastCGI, not
 * HTTP), so both runtimes are probed through the LB's per-pool routes.
 */
async function probeWebPool(name) {
  const r = await httpProbe(LB_PORT, `/__pool/${name}/healthz`);
  if (r.ok && webRuntime() === 'fpm') {
    r.detail += await fpmPoolDetail(name);
  }
  return r;
}

async function probeMysql() {
  if (mysql === null) return { ok: false, detail: mysqlLoadError };
  await withTimeout(getPool().query('SELECT 1'), PROBE_TIMEOUT_MS);
  return { ok: true, detail: 'SELECT 1 ok' };
}

async function probeRedis() {
  const reply = await redis.command('PING');
  return { ok: reply === 'PONG', detail: String(reply) };
}

async function probeQueue(key) {
  const depth = await redis.command('LLEN', key);
  return { ok: depth <= QUEUE_WARN_DEPTH, detail: 'depth=' + depth };
}

async function probeWorker(instanceId) {
  const value = await redis.command('GET', 'heartbeat:' + instanceId);
  if (value === null) return { ok: false, detail: 'no heartbeat (down?)' };
  const ts = parseInt(value, 10);
  if (!Number.isFinite(ts)) return { ok: true, detail: 'heartbeat present' };
  const age = Math.max(0, Math.floor(Date.now() / 1000) - ts);
  return { ok: true, detail: `heartbeat ${age}s ago` };
}

async function buildStatus() {
  const start = process.hrtime.bigint();
  const services = await Promise.all([
    timedProbe('lb', () => httpProbe(LB_PORT)),
    timedProbe('web1', () => probeWebPool('web1')),
    timedProbe('web2', () => probeWebPool('web2')),
    timedProbe('status', async () => ({ ok: true, detail: 'self' })),
    timedProbe('mysql', probeMysql),
    timedProbe('redis', probeRedis),
    timedProbe('queue:pdf', () => probeQueue('queue:pdf')),
    timedProbe('queue:email', () => probeQueue('queue:email')),
    timedProbe('queue:webhook', () => probeQueue('queue:webhook')),
    timedProbe('worker-pdf', () => probeWorker('worker-pdf')),
    timedProbe('worker-email', () => probeWorker('worker-email')),
    timedProbe('worker-webhook', () => probeWorker('worker-webhook')),
  ]);
  metricTiming('status.probe_ms', elapsedMs(start));
  return {
    ok: services.every((s) => s.ok),
    generated_at: new Date().toISOString(),
    services,
  };
}

// ---------------------------------------------------------------------------
// /api/stats
// ---------------------------------------------------------------------------

/** UTC hourly bucket, zero-padded YmdH (e.g. 2026061013). Never local time. */
function utcHourBucket(d = new Date()) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getUTCFullYear()}${pad(d.getUTCMonth() + 1)}${pad(d.getUTCDate())}${pad(d.getUTCHours())}`;
}

async function buildStats() {
  if (mysql === null) throw new Error(mysqlLoadError);
  const p = getPool();
  const q = (sql) => withTimeout(p.query(sql), PROBE_TIMEOUT_MS, 'mysql query timeout').then(([rows]) => rows);

  const [users, forms, subs, pdfRows, emailRows, topRows] = await Promise.all([
    q('SELECT COUNT(*) AS n FROM users'),
    q('SELECT COUNT(*) AS n FROM forms'),
    q('SELECT COUNT(*) AS n FROM submissions'),
    q('SELECT status, COUNT(*) AS n FROM pdf_jobs GROUP BY status'),
    q('SELECT status, COUNT(*) AS n FROM emails GROUP BY status'),
    q(
      'SELECT f.title, COUNT(s.id) AS count FROM forms f ' +
        'JOIN submissions s ON s.form_id = f.id ' +
        'GROUP BY f.id, f.title ORDER BY count DESC, f.id ASC LIMIT 5'
    ),
  ]);

  const pdf_jobs = { pending: 0, processing: 0, done: 0, failed: 0 };
  for (const r of pdfRows) {
    if (Object.prototype.hasOwnProperty.call(pdf_jobs, r.status)) pdf_jobs[r.status] = Number(r.n);
  }
  const emails = { pending: 0, sent: 0, failed: 0 };
  for (const r of emailRows) {
    if (Object.prototype.hasOwnProperty.call(emails, r.status)) emails[r.status] = Number(r.n);
  }

  // Only non-MySQL datum: hourly counter from Redis. Missing key (or Redis down) => 0.
  let submissionsLastHour = 0;
  try {
    const v = await redis.command('GET', 'stats:submissions:' + utcHourBucket());
    if (v !== null) submissionsLastHour = parseInt(v, 10) || 0;
  } catch {
    submissionsLastHour = 0;
  }

  return {
    users: Number(users[0].n),
    forms: Number(forms[0].n),
    submissions_total: Number(subs[0].n),
    submissions_last_hour: submissionsLastHour,
    pdf_jobs,
    emails,
    top_forms: topRows.map((r) => ({ title: r.title, count: Number(r.count) })),
  };
}

// ---------------------------------------------------------------------------
// Dashboard HTML (self-contained: inline CSS + JS, no external assets)
// ---------------------------------------------------------------------------

const DASHBOARD_HTML = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ReliableForm · Status</title>
<style>
  :root { --accent: #f97316; --bg: #f6f7fb; --ink: #1f2430; --muted: #6b7280; --ok: #16a34a; --bad: #dc2626; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--ink);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 15px; line-height: 1.45; }
  a { color: var(--accent); }
  .container { max-width: 1040px; margin: 0 auto; padding: 28px 20px 56px; }
  header { display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
  h1 { font-size: 22px; margin: 0; letter-spacing: -0.01em; }
  h1 .dot { color: var(--accent); }
  #updated { color: var(--muted); font-size: 13px; }
  .banner { border-radius: 12px; padding: 14px 18px; font-weight: 600; margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(16,24,40,.08); }
  .banner.ok { background: #dcfce7; color: #166534; }
  .banner.bad { background: #fee2e2; color: #991b1b; }
  .banner.unknown { background: #fff; color: var(--muted); }
  h2 { font-size: 14px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin: 26px 0 10px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(188px, 1fr)); gap: 12px; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(16,24,40,.08); padding: 14px 16px; }
  .svc.down { box-shadow: 0 1px 3px rgba(16,24,40,.08), inset 0 0 0 1px #fecaca; }
  .svc-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
  .svc-name { font-weight: 600; font-size: 14px; overflow-wrap: anywhere; }
  .mark { font-weight: 700; font-size: 15px; }
  .mark.ok { color: var(--ok); }
  .mark.bad { color: var(--bad); }
  .svc-latency { color: var(--muted); font-size: 12px; margin-top: 4px; font-variant-numeric: tabular-nums; }
  .svc-detail { font-size: 13px; margin-top: 2px; color: var(--ink); overflow-wrap: anywhere; }
  .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(188px, 1fr)); gap: 12px; }
  .stat-label { color: var(--muted); font-size: 13px; }
  .stat-value { font-size: 26px; font-weight: 700; margin-top: 2px; font-variant-numeric: tabular-nums; }
  .stat-sub { color: var(--muted); font-size: 12px; margin-top: 4px; font-variant-numeric: tabular-nums; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th { text-align: left; color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 8px 8px 0; border-bottom: 1px solid #eef0f4; font-weight: 600; }
  th.num, td.num { text-align: right; font-variant-numeric: tabular-nums; }
  td { padding: 8px 8px 8px 0; border-bottom: 1px solid #f3f4f7; overflow-wrap: anywhere; }
  tr:last-child td { border-bottom: 0; }
  .empty { color: var(--muted); font-size: 13px; }
  #stats-unavailable { color: var(--muted); }
  footer { margin-top: 36px; color: var(--muted); font-size: 13px; text-align: center; }
  :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
</style>
</head>
<body>
<div class="container">
  <header>
    <h1>ReliableForm <span class="dot">·</span> Status</h1>
    <span id="updated">loading…</span>
  </header>

  <div id="banner" class="banner unknown" role="status">Checking services…</div>

  <h2>Services</h2>
  <div id="services" class="grid" aria-live="polite"></div>

  <h2>Stats</h2>
  <div id="stats" class="stats-grid" style="display:none">
    <div class="card"><div class="stat-label">Forms</div><div class="stat-value" id="stat-forms">–</div><div class="stat-sub" id="stat-users">–</div></div>
    <div class="card"><div class="stat-label">Submissions (total)</div><div class="stat-value" id="stat-subs">–</div></div>
    <div class="card"><div class="stat-label">Submissions (last hour)</div><div class="stat-value" id="stat-hour">–</div></div>
    <div class="card"><div class="stat-label">PDF jobs</div><div class="stat-value" id="stat-pdf-done">–</div><div class="stat-sub" id="stat-pdf-detail">–</div></div>
    <div class="card"><div class="stat-label">Emails</div><div class="stat-value" id="stat-email-sent">–</div><div class="stat-sub" id="stat-email-detail">–</div></div>
  </div>
  <div id="stats-unavailable" class="card" style="display:none">stats unavailable</div>

  <h2>Top forms</h2>
  <div class="card">
    <table>
      <thead><tr><th>Form</th><th class="num">Submissions</th></tr></thead>
      <tbody id="top-forms"><tr><td colspan="2" class="empty">no data yet</td></tr></tbody>
    </table>
  </div>

  <footer>served by __INSTANCE__</footer>
</div>
<script>
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function renderStatus(data) {
    var banner = $('banner');
    if (data.ok) {
      banner.className = 'banner ok';
      banner.textContent = 'All systems operational';
    } else {
      var failing = data.services.filter(function (s) { return !s.ok; }).length;
      banner.className = 'banner bad';
      banner.textContent = 'Degraded — ' + failing + ' of ' + data.services.length + ' checks failing';
    }
    var grid = $('services');
    grid.replaceChildren();
    data.services.forEach(function (s) {
      var card = el('div', 'card svc' + (s.ok ? '' : ' down'));
      var top = el('div', 'svc-top');
      top.appendChild(el('span', 'svc-name', s.name));
      top.appendChild(el('span', 'mark ' + (s.ok ? 'ok' : 'bad'), s.ok ? '\\u2713' : '\\u2717'));
      card.appendChild(top);
      card.appendChild(el('div', 'svc-latency', s.latency_ms + ' ms'));
      card.appendChild(el('div', 'svc-detail', s.detail || ''));
      grid.appendChild(card);
    });
  }

  function renderStats(st) {
    $('stats-unavailable').style.display = 'none';
    $('stats').style.display = '';
    $('stat-forms').textContent = st.forms;
    $('stat-users').textContent = st.users + ' user' + (st.users === 1 ? '' : 's');
    $('stat-subs').textContent = st.submissions_total;
    $('stat-hour').textContent = st.submissions_last_hour;
    var pj = st.pdf_jobs || {};
    $('stat-pdf-done').textContent = (pj.done || 0) + ' done';
    $('stat-pdf-detail').textContent =
      (pj.pending || 0) + ' pending · ' + (pj.processing || 0) + ' processing · ' + (pj.failed || 0) + ' failed';
    var em = st.emails || {};
    $('stat-email-sent').textContent = (em.sent || 0) + ' sent';
    $('stat-email-detail').textContent = (em.pending || 0) + ' pending · ' + (em.failed || 0) + ' failed';
    var body = $('top-forms');
    body.replaceChildren();
    var top = st.top_forms || [];
    if (top.length === 0) {
      var tr0 = el('tr');
      var td0 = el('td', 'empty', 'no submissions yet');
      td0.colSpan = 2;
      tr0.appendChild(td0);
      body.appendChild(tr0);
      return;
    }
    top.forEach(function (f) {
      var tr = el('tr');
      tr.appendChild(el('td', null, f.title));
      tr.appendChild(el('td', 'num', String(f.count)));
      body.appendChild(tr);
    });
  }

  function statsUnavailable() {
    $('stats').style.display = 'none';
    $('stats-unavailable').style.display = '';
  }

  function refresh() {
    fetch('/api/status', { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(renderStatus)
      .catch(function () {
        var banner = $('banner');
        banner.className = 'banner bad';
        banner.textContent = 'Status API unreachable';
      })
      .then(function () {
        $('updated').textContent = 'last updated ' + new Date().toLocaleTimeString();
      });
    fetch('/api/stats', { cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) throw new Error('stats unavailable');
        return r.json();
      })
      .then(renderStats)
      .catch(statsUnavailable);
  }

  refresh();
  setInterval(refresh, 5000);
})();
</script>
</body>
</html>
`.replace(/__INSTANCE__/g, INSTANCE.replace(/[^a-zA-Z0-9._:-]/g, ''));

// ---------------------------------------------------------------------------
// HTTP server
// ---------------------------------------------------------------------------

function sendJson(res, status, body) {
  const payload = JSON.stringify(body);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(payload),
    'X-Served-By': INSTANCE,
  });
  res.end(payload);
}

function sendHtml(res, status, html) {
  res.writeHead(status, {
    'Content-Type': 'text/html; charset=utf-8',
    'Content-Length': Buffer.byteLength(html),
    'X-Served-By': INSTANCE,
  });
  res.end(html);
}

// ---------------------------------------------------------------------------
// Observability (iteration 1): one SERVER span + one app-log line per request.
// ---------------------------------------------------------------------------

/** Epoch milliseconds with sub-ms precision (monotonic within a request). */
const nowMs = () => performance.timeOrigin + performance.now();

/** Static route table — the span/log route label ('http.route'). */
function routeOf(url) {
  if (url === '/healthz' || url === '/api/status' || url === '/api/stats') return url;
  if (url === '/status' || url === '/status/') return '/status';
  return url.length > 512 ? url.slice(0, 512) : url; // unmatched: raw path
}

/**
 * Attach per-request tracing + logging: parse/adopt traceparent, answer with
 * X-Trace-Id on every route, and on response end emit one SERVER span
 * (spooled for the otel sidecar) and one http_request app-log line.
 */
function instrument(req, res, url) {
  const startMs = nowMs();
  const trace = otel.startTrace(req.headers.traceparent);
  res.setHeader('X-Trace-Id', trace.traceId);
  res.on('finish', () => {
    const endMs = nowMs();
    const method = req.method || 'GET';
    const route = routeOf(url);
    const status = res.statusCode;
    const requestId =
      typeof req.headers['x-request-id'] === 'string' ? req.headers['x-request-id'] : '';
    otel.span(
      trace,
      `${method} ${route}`,
      startMs,
      endMs,
      otel.KIND_SERVER,
      {
        'http.request.method': method,
        'http.route': route,
        'http.response.status_code': status,
        'app.request_id': requestId,
      },
      status >= 500
    );
    otel.flush();
    applog.event('http_request', trace.traceId, {
      method,
      route,
      status,
      dur_ms: Math.round((endMs - startMs) * 10) / 10,
      request_id: requestId,
    });
  });
}

const server = http.createServer(async (req, res) => {
  metricIncrement('status.request');
  const url = (req.url || '/').split('?')[0];
  instrument(req, res, url);
  try {
    if (url === '/healthz') {
      return sendJson(res, 200, { ok: true, instance: INSTANCE });
    }
    if (url === '/api/status') {
      return sendJson(res, 200, await buildStatus());
    }
    if (url === '/api/stats') {
      try {
        return sendJson(res, 200, await buildStats());
      } catch (err) {
        return sendJson(res, 503, { ok: false, error: errDetail(err) });
      }
    }
    if (url === '/status' || url === '/status/') {
      return sendHtml(res, 200, DASHBOARD_HTML);
    }
    return sendJson(res, 404, { ok: false, error: 'not found' });
  } catch (err) {
    process.stderr.write(`[status] request error: ${err && err.stack ? err.stack : err}\n`);
    if (!res.headersSent) sendJson(res, 500, { ok: false, error: 'internal error' });
    else res.end();
  }
});

// ---------------------------------------------------------------------------
// Liveness heartbeat: SETEX heartbeat:status 15 <unix ts> every 10s.
// ---------------------------------------------------------------------------

function beat() {
  redis
    .command('SETEX', 'heartbeat:' + INSTANCE, '15', String(Math.floor(Date.now() / 1000)))
    .catch(() => {}); // swallow — Redis being down must never hurt us
}
const heartbeatTimer = setInterval(beat, 10000);
heartbeatTimer.unref();

// ---------------------------------------------------------------------------
// Startup / shutdown
// ---------------------------------------------------------------------------

server.listen(STATUS_PORT, '127.0.0.1', () => {
  console.log(`status service listening on 127.0.0.1:${STATUS_PORT}`);
  beat();
});

server.on('error', (err) => {
  process.stderr.write(`[status] fatal: ${err.message}\n`);
  process.exit(1);
});

let shuttingDown = false;
function shutdown() {
  if (shuttingDown) return;
  shuttingDown = true;
  clearInterval(heartbeatTimer);
  redis.close();
  if (pool !== null) pool.end().catch(() => {});
  try {
    statsdSock.close();
  } catch {
    /* already closed */
  }
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 3000).unref();
}
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);

// A status page must outlive its dependencies: log, never die.
process.on('uncaughtException', (err) => {
  process.stderr.write(`[status] uncaught exception: ${err && err.stack ? err.stack : err}\n`);
});
process.on('unhandledRejection', (reason) => {
  process.stderr.write(`[status] unhandled rejection: ${reason && reason.stack ? reason.stack : reason}\n`);
});
