'use strict';

const { test } = require('node:test');
const assert = require('node:assert');
const net = require('net');

const { RedisClient, RedisError } = require('../redis');

/**
 * Minimal in-process RESP server: parses inbound commands (the exact frames
 * encodeCommand emits) and answers each with a canned reply chosen by the
 * command name. Lets the suite exercise redis.js against every reply type
 * without a real Redis.
 */

/** Parse one inbound '*N $len arg ...' command. Returns [args, nextPos] | null. */
function parseInboundCommand(buf, pos) {
  if (pos >= buf.length || String.fromCharCode(buf[pos]) !== '*') return null;
  let eol = buf.indexOf('\r\n', pos);
  if (eol === -1) return null;
  const count = parseInt(buf.toString('utf8', pos + 1, eol), 10);
  let p = eol + 2;
  const args = [];
  for (let i = 0; i < count; i++) {
    if (p >= buf.length || String.fromCharCode(buf[p]) !== '$') return null;
    eol = buf.indexOf('\r\n', p);
    if (eol === -1) return null;
    const len = parseInt(buf.toString('utf8', p + 1, eol), 10);
    if (buf.length < eol + 2 + len + 2) return null;
    args.push(buf.toString('utf8', eol + 2, eol + 2 + len));
    p = eol + 2 + len + 2;
  }
  return [args, p];
}

/** @param {(args: string[]) => string|Buffer} handler reply per command */
function startServer(handler) {
  return new Promise((resolve) => {
    const server = net.createServer((sock) => {
      let buf = Buffer.alloc(0);
      sock.on('data', (chunk) => {
        buf = Buffer.concat([buf, chunk]);
        let parsed;
        while ((parsed = parseInboundCommand(buf, 0)) !== null) {
          buf = buf.subarray(parsed[1]);
          sock.write(handler(parsed[0]));
        }
      });
    });
    server.listen(0, '127.0.0.1', () => resolve(server));
  });
}

function cannedHandler(args) {
  switch (args[0]) {
    case 'PING':   return '+PONG\r\n';
    case 'GETNIL': return '$-1\r\n';
    case 'GET':    return '$5\r\nhello\r\n';
    case 'GETUNI': { // multibyte bulk — $len is BYTES, not chars
      const v = 'héllo 🙂';
      return '$' + Buffer.byteLength(v) + '\r\n' + v + '\r\n';
    }
    case 'LLEN':   return ':42\r\n';
    case 'LRANGE': return '*2\r\n$1\r\na\r\n$1\r\nb\r\n';
    case 'EMPTYA': return '*0\r\n';
    case 'NILARR': return '*-1\r\n';
    case 'NESTED': return '*2\r\n:1\r\n*2\r\n+ok\r\n$-1\r\n';
    case 'ECHON':  return ':' + args.length + '\r\n'; // pipelining: echoes argc
    case 'BOOM':   return '-ERR boom goes the dynamite\r\n';
    default:       return '-ERR unknown command\r\n';
  }
}

async function withClient(fn) {
  const server = await startServer(cannedHandler);
  const client = new RedisClient('127.0.0.1', server.address().port, 2000);
  try {
    await fn(client);
  } finally {
    client.close();
    server.close();
  }
}

test('simple string reply resolves to its text', () =>
  withClient(async (c) => {
    assert.strictEqual(await c.command('PING'), 'PONG');
  }));

test('bulk reply resolves to the string; nil bulk to null', () =>
  withClient(async (c) => {
    assert.strictEqual(await c.command('GET', 'k'), 'hello');
    assert.strictEqual(await c.command('GETNIL', 'k'), null);
  }));

test('multibyte bulk decodes by byte length', () =>
  withClient(async (c) => {
    assert.strictEqual(await c.command('GETUNI'), 'héllo 🙂');
  }));

test('integer reply resolves to a number', () =>
  withClient(async (c) => {
    assert.strictEqual(await c.command('LLEN', 'q'), 42);
  }));

test('error reply rejects with RedisError (connection stays usable)', () =>
  withClient(async (c) => {
    await assert.rejects(c.command('BOOM'), (err) => {
      assert.ok(err instanceof RedisError);
      assert.match(err.message, /boom goes the dynamite/);
      return true;
    });
    // -ERR is a normal reply, not an IO failure: the next command must work.
    assert.strictEqual(await c.command('PING'), 'PONG');
  }));

test('array replies: values, empty array, nil array, nested', () =>
  withClient(async (c) => {
    assert.deepStrictEqual(await c.command('LRANGE', 'k', '0', '-1'), ['a', 'b']);
    assert.deepStrictEqual(await c.command('EMPTYA'), []);
    assert.strictEqual(await c.command('NILARR'), null);
    assert.deepStrictEqual(await c.command('NESTED'), [1, ['ok', null]]);
  }));

test('pipelining: concurrent commands resolve in order', () =>
  withClient(async (c) => {
    const replies = await Promise.all([
      c.command('ECHON', 'a'),          // argc 2
      c.command('ECHON', 'a', 'b'),     // argc 3
      c.command('PING'),
      c.command('ECHON', 'a', 'b', 'c', 'd'), // argc 5
      c.command('LLEN', 'q'),
    ]);
    assert.deepStrictEqual(replies, [2, 3, 'PONG', 5, 42]);
  }));

test('replies split across TCP chunks are reassembled', async () => {
  // Bespoke server: writes one bulk reply in three delayed pieces.
  const server = await new Promise((resolve) => {
    const s = net.createServer((sock) => {
      sock.on('data', () => {
        sock.write('$11\r\nhel');
        setTimeout(() => sock.write('lo wo'), 10);
        setTimeout(() => sock.write('rld\r\n'), 20);
      });
    });
    s.listen(0, '127.0.0.1', () => resolve(s));
  });
  const client = new RedisClient('127.0.0.1', server.address().port, 2000);
  try {
    assert.strictEqual(await client.command('GET', 'k'), 'hello world');
  } finally {
    client.close();
    server.close();
  }
});
