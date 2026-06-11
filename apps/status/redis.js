'use strict';

/**
 * Tiny inline Redis RESP2 client over a single lazily-(re)connected
 * net.Socket. No npm dependency. Commands return Promises; every command and
 * the connect itself carry a 2s timeout. Any IO error rejects the in-flight
 * commands, marks the client down and drops the socket — the next command
 * reconnects. Never crashes the process.
 */

const net = require('net');

class RedisError extends Error {
  constructor(message) {
    super(message);
    this.name = 'RedisError';
  }
}

function encodeCommand(args) {
  const parts = ['*' + args.length + '\r\n'];
  for (const a of args) {
    const s = String(a);
    parts.push('$' + Buffer.byteLength(s) + '\r\n' + s + '\r\n');
  }
  return parts.join('');
}

function findLine(buf, pos) {
  const idx = buf.indexOf('\r\n', pos);
  if (idx === -1) return null;
  return [buf.toString('utf8', pos, idx), idx + 2];
}

/** Returns [value, nextPos], or null when the buffer is incomplete. */
function parseReply(buf, pos) {
  if (pos >= buf.length) return null;
  const type = String.fromCharCode(buf[pos]);
  const line = findLine(buf, pos + 1);
  if (line === null) return null;
  const [text, next] = line;
  switch (type) {
    case '+':
      return [text, next];
    case '-':
      return [new RedisError(text), next];
    case ':':
      return [parseInt(text, 10), next];
    case '$': {
      const len = parseInt(text, 10);
      if (len === -1) return [null, next]; // nil bulk
      if (buf.length < next + len + 2) return null;
      return [buf.toString('utf8', next, next + len), next + len + 2];
    }
    case '*': {
      const count = parseInt(text, 10);
      if (count === -1) return [null, next];
      const arr = [];
      let p = next;
      for (let i = 0; i < count; i++) {
        const item = parseReply(buf, p);
        if (item === null) return null;
        arr.push(item[0]);
        p = item[1];
      }
      return [arr, p];
    }
    default:
      throw new Error('RESP protocol desync (unexpected type byte 0x' + buf[pos].toString(16) + ')');
  }
}

class RedisClient {
  constructor(host, port, timeoutMs = 2000) {
    this.host = host;
    this.port = port;
    this.timeoutMs = timeoutMs;
    this.socket = null;
    this.ready = false;
    this.connectPromise = null;
    this.pending = []; // FIFO of { resolve, reject, timer }
    this.buffer = Buffer.alloc(0);
    this.down = false;
  }

  _connect() {
    if (this.ready && this.socket && !this.socket.destroyed) {
      return Promise.resolve();
    }
    if (this.connectPromise) return this.connectPromise;

    this.connectPromise = new Promise((resolve, reject) => {
      const sock = net.connect({ host: this.host, port: this.port });
      this.socket = sock;
      sock.setNoDelay(true);
      const timer = setTimeout(() => {
        sock.destroy(new Error('redis connect timeout'));
      }, this.timeoutMs);
      sock.once('connect', () => {
        clearTimeout(timer);
        this.ready = true;
        this.down = false;
        resolve();
      });
      sock.on('data', (chunk) => this._onData(chunk));
      sock.on('error', (err) => {
        clearTimeout(timer);
        this._teardown(err);
        reject(err); // no-op if already resolved
      });
      sock.on('close', () => {
        clearTimeout(timer);
        const err = new Error('redis connection closed');
        this._teardown(err);
        reject(err);
      });
    });
    const clear = () => {
      this.connectPromise = null;
    };
    this.connectPromise.then(clear, clear);
    return this.connectPromise;
  }

  /** Send a command, e.g. command('LLEN', 'queue:pdf'). Resolves the RESP reply. */
  async command(...args) {
    await this._connect();
    return new Promise((resolve, reject) => {
      const entry = { resolve, reject, timer: null };
      entry.timer = setTimeout(() => {
        // Reply order is now ambiguous — drop the whole connection.
        this._teardown(new Error('redis command timeout'));
      }, this.timeoutMs);
      this.pending.push(entry);
      this.socket.write(encodeCommand(args), (err) => {
        if (err) this._teardown(err);
      });
    });
  }

  _onData(chunk) {
    this.buffer = this.buffer.length ? Buffer.concat([this.buffer, chunk]) : chunk;
    while (this.pending.length > 0) {
      let parsed;
      try {
        parsed = parseReply(this.buffer, 0);
      } catch (err) {
        this._teardown(err);
        return;
      }
      if (parsed === null) return; // wait for more bytes
      this.buffer = this.buffer.subarray(parsed[1]);
      const entry = this.pending.shift();
      clearTimeout(entry.timer);
      if (parsed[0] instanceof RedisError) entry.reject(parsed[0]);
      else entry.resolve(parsed[0]);
    }
    if (this.buffer.length > 0) {
      // Bytes with no in-flight command: protocol desync, start fresh.
      this._teardown(new Error('RESP protocol desync (unsolicited reply)'));
    }
  }

  _teardown(err) {
    this.down = true;
    this.ready = false;
    const pend = this.pending;
    this.pending = [];
    for (const e of pend) {
      clearTimeout(e.timer);
      e.reject(err);
    }
    this.buffer = Buffer.alloc(0);
    if (this.socket) {
      const s = this.socket;
      this.socket = null;
      s.removeAllListeners();
      s.on('error', () => {}); // swallow late socket errors
      s.destroy();
    }
  }

  close() {
    this._teardown(new Error('redis client closed'));
  }
}

module.exports = { RedisClient, RedisError };
