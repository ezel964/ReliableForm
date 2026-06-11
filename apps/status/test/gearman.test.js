'use strict';

const { test } = require('node:test');
const assert = require('node:assert');
const net = require('net');

const { parseGearmanStatus, fetchGearmanStatus } = require('../gearman');

// ---------------------------------------------------------------------------
// parseGearmanStatus — canned admin status text
// ---------------------------------------------------------------------------

test('parses name/queued/running/workers lines into the function map', () => {
  const text = 'rf_pdf\t3\t1\t2\nrf_email\t0\t0\t1\nrf_webhook\t12\t0\t0\n.\n';
  assert.deepStrictEqual(parseGearmanStatus(text), {
    rf_pdf: { queued: 3, running: 1, workers: 2 },
    rf_email: { queued: 0, running: 0, workers: 1 },
    rf_webhook: { queued: 12, running: 0, workers: 0 },
  });
});

test('empty status (terminator only) parses to an empty map', () => {
  assert.deepStrictEqual(parseGearmanStatus('.\n'), {});
});

test('CRLF line endings are tolerated', () => {
  const text = 'rf_pdf\t1\t0\t1\r\n.\r\n';
  assert.deepStrictEqual(parseGearmanStatus(text), {
    rf_pdf: { queued: 1, running: 0, workers: 1 },
  });
});

test('malformed/unknown lines are skipped, valid ones kept', () => {
  const text = [
    'garbage with no tabs',
    'rf_pdf\t2\t0\t1',
    'short\t1\t2', // wrong column count
    'rf_email\tNaN\t0\t1', // non-numeric count
    '\t1\t2\t3', // empty function name
    '.',
    '',
  ].join('\n');
  assert.deepStrictEqual(parseGearmanStatus(text), {
    rf_pdf: { queued: 2, running: 0, workers: 1 },
  });
});

// ---------------------------------------------------------------------------
// fetchGearmanStatus — in-process net server speaking canned admin replies
// (same approach as resp.test.js).
// ---------------------------------------------------------------------------

/** @param {(sock: net.Socket, line: string) => void} onCommand */
function startServer(onCommand) {
  return new Promise((resolve) => {
    const server = net.createServer((sock) => {
      let buf = '';
      sock.on('data', (chunk) => {
        buf += chunk.toString('utf8');
        let idx;
        while ((idx = buf.indexOf('\n')) !== -1) {
          const line = buf.slice(0, idx);
          buf = buf.slice(idx + 1);
          onCommand(sock, line);
        }
      });
    });
    server.listen(0, '127.0.0.1', () => resolve(server));
  });
}

test('one roundtrip: sends "status", resolves the parsed map', async () => {
  const server = await startServer((sock, line) => {
    assert.strictEqual(line, 'status');
    sock.write('rf_pdf\t0\t0\t1\nrf_email\t5\t1\t1\n.\n');
  });
  try {
    const map = await fetchGearmanStatus('127.0.0.1', server.address().port, 1000);
    assert.deepStrictEqual(map, {
      rf_pdf: { queued: 0, running: 0, workers: 1 },
      rf_email: { queued: 5, running: 1, workers: 1 },
    });
  } finally {
    server.close();
  }
});

test('replies split across TCP chunks are reassembled', async () => {
  const server = await startServer((sock) => {
    sock.write('rf_pdf\t1');
    setTimeout(() => sock.write('\t0\t1\nrf_we'), 10);
    setTimeout(() => sock.write('bhook\t0\t0\t1\n.\n'), 20);
  });
  try {
    const map = await fetchGearmanStatus('127.0.0.1', server.address().port, 1000);
    assert.deepStrictEqual(map, {
      rf_pdf: { queued: 1, running: 0, workers: 1 },
      rf_webhook: { queued: 0, running: 0, workers: 1 },
    });
  } finally {
    server.close();
  }
});

test('connection refused rejects', async () => {
  // Grab a port that is definitely closed: bind, note it, close it.
  const server = await new Promise((resolve) => {
    const s = net.createServer();
    s.listen(0, '127.0.0.1', () => resolve(s));
  });
  const port = server.address().port;
  await new Promise((resolve) => server.close(resolve));
  await assert.rejects(fetchGearmanStatus('127.0.0.1', port, 1000));
});

test('server closing before the "." terminator rejects', async () => {
  const server = await startServer((sock) => {
    sock.end('rf_pdf\t1\t0\t1\n'); // no terminator
  });
  try {
    await assert.rejects(
      fetchGearmanStatus('127.0.0.1', server.address().port, 1000),
      /closed before status terminator/
    );
  } finally {
    server.close();
  }
});

test('a server that never answers rejects with the timeout error', async () => {
  const server = await startServer(() => {
    /* swallow the command, answer nothing */
  });
  try {
    await assert.rejects(
      fetchGearmanStatus('127.0.0.1', server.address().port, 150),
      /gearmand status timeout/
    );
  } finally {
    server.close();
  }
});
