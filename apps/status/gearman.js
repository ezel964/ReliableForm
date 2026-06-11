'use strict';

/**
 * Gearmand admin ("text") protocol client — one TCP connect + `status`
 * roundtrip per call, same hand-rolled socket style as redis.js. Used only
 * when launch.sh resolved QUEUE_DRIVER=gearman (storage/run/queue-driver
 * marker).
 *
 * `status` answers one line per function — "name\tqueued\trunning\tworkers"
 * — terminated by a lone ".". Note: gearmand's second column is the TOTAL
 * job count for the function (queued + running); the contract labels it
 * "queued" and the probes treat it as the queue depth.
 */

const net = require('net');

/**
 * Parse the raw admin status text (everything up to and including the "."
 * terminator line). Unknown/malformed lines are tolerated and skipped.
 *
 * @param {string} text
 * @returns {Object<string, {queued: number, running: number, workers: number}>}
 */
function parseGearmanStatus(text) {
  const out = {};
  for (const rawLine of String(text).split('\n')) {
    const line = rawLine.endsWith('\r') ? rawLine.slice(0, -1) : rawLine;
    if (line === '' || line === '.') continue;
    const parts = line.split('\t');
    if (parts.length !== 4 || parts[0] === '') continue;
    const queued = parseInt(parts[1], 10);
    const running = parseInt(parts[2], 10);
    const workers = parseInt(parts[3], 10);
    if (!Number.isFinite(queued) || !Number.isFinite(running) || !Number.isFinite(workers)) continue;
    out[parts[0]] = { queued, running, workers };
  }
  return out;
}

/** Has the buffered response reached the lone-"." terminator line? */
function hasTerminator(buf) {
  return buf === '.\n' || buf === '.\r\n' || buf.includes('\n.\n') || buf.includes('\n.\r\n');
}

/**
 * One TCP roundtrip: connect, send "status\n", buffer until the "."
 * terminator, parse. Rejects on connect error, timeout, or a connection
 * that closes before the terminator. Never leaves the socket open.
 *
 * @returns {Promise<Object<string, {queued: number, running: number, workers: number}>>}
 */
function fetchGearmanStatus(host, port, timeoutMs = 2000) {
  return new Promise((resolve, reject) => {
    const sock = net.connect({ host, port });
    let buf = '';
    let settled = false;
    const done = (err, value) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      sock.removeAllListeners();
      sock.on('error', () => {}); // swallow late socket errors
      sock.destroy();
      if (err) reject(err);
      else resolve(value);
    };
    const timer = setTimeout(() => done(new Error('gearmand status timeout')), timeoutMs);
    sock.setNoDelay(true);
    sock.once('connect', () => sock.write('status\n'));
    sock.on('data', (chunk) => {
      buf += chunk.toString('utf8');
      if (hasTerminator(buf)) done(null, parseGearmanStatus(buf));
    });
    sock.on('error', (err) => done(err));
    sock.on('close', () => done(new Error('gearmand closed before status terminator')));
  });
}

module.exports = { parseGearmanStatus, fetchGearmanStatus };
