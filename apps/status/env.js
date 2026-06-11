'use strict';

/**
 * Tiny hand-rolled .env reader for the status service.
 * KEY=VALUE lines, `#` comment lines, optional single/double quotes around the
 * value. Real environment variables override file values; defaults below
 * (mirroring ARCHITECTURE.md) apply when a key is absent everywhere.
 */

const fs = require('fs');
const path = require('path');

const PROJECT_ROOT = path.resolve(__dirname, '../..');

const DEFAULTS = {
  APP_ENV: 'dev',
  APP_URL: 'http://localhost:8080',
  LB_PORT: '8080',
  WEB1_PORT: '9001',
  WEB2_PORT: '9002',
  STATUS_PORT: '9301',
  DB_HOST: '127.0.0.1',
  DB_PORT: '3306',
  DB_NAME: 'reliableform',
  DB_USER: 'reliableform',
  DB_PASS: 'reliableform',
  REDIS_HOST: '127.0.0.1',
  REDIS_PORT: '6379',
  STATSD_HOST: '127.0.0.1',
  STATSD_PORT: '8125',
  SESSION_TTL: '86400',
};

function parseEnvFile(file) {
  let raw;
  try {
    raw = fs.readFileSync(file, 'utf8');
  } catch {
    return {}; // no .env yet — defaults still apply
  }
  const out = {};
  for (const rawLine of raw.split(/\r?\n/)) {
    const line = rawLine.trim();
    if (line === '' || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq <= 0) continue;
    const key = line.slice(0, eq).trim();
    let val = line.slice(eq + 1).trim();
    if (
      val.length >= 2 &&
      ((val.startsWith('"') && val.endsWith('"')) ||
        (val.startsWith("'") && val.endsWith("'")))
    ) {
      val = val.slice(1, -1);
    }
    if (key !== '') out[key] = val;
  }
  return out;
}

/**
 * Build a get/getInt pair bound to one .env file. The optional path parameter
 * exists so tests can point the parser at a fixture; the default instance
 * below keeps the original behavior (PROJECT_ROOT/.env) for server.js.
 */
function createEnv(file = path.join(PROJECT_ROOT, '.env')) {
  const fileValues = parseEnvFile(file);

  /** Config lookup order: real env var > .env file > explicit default > ARCHITECTURE.md default. */
  function get(key, fallback) {
    if (process.env[key] !== undefined && process.env[key] !== '') {
      return process.env[key];
    }
    if (fileValues[key] !== undefined) return fileValues[key];
    if (fallback !== undefined) return fallback;
    return DEFAULTS[key];
  }

  function getInt(key, fallback) {
    const n = parseInt(get(key, fallback), 10);
    if (Number.isFinite(n)) return n;
    return parseInt(DEFAULTS[key] ?? '0', 10);
  }

  return { get, getInt };
}

const defaultEnv = createEnv();

module.exports = {
  get: defaultEnv.get,
  getInt: defaultEnv.getInt,
  PROJECT_ROOT,
  parseEnvFile,
  createEnv,
  DEFAULTS,
};
