'use strict';

const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');

const { parseEnvFile, createEnv, DEFAULTS } = require('../env');

// Fixture keys are TESTENV_-prefixed so the real environment never collides.
function fixture(content) {
  const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'rf-env-')), '.env');
  fs.writeFileSync(file, content);
  return file;
}

test('parseEnvFile: KEY=VALUE, comments, quotes, malformed lines', () => {
  const file = fixture(
    [
      '# a comment line',
      '',
      'TESTENV_PLAIN=hello',
      '  TESTENV_TRIM  =  spaced  ',
      'TESTENV_DQ="double quoted"',
      "TESTENV_SQ='single quoted'",
      'TESTENV_EQ=a=b=c',
      'TESTENV_EMPTY=',
      'TESTENV_HALFQ="unbalanced',
      'no-equals-sign-at-all',
      '=value-without-key',
      'TESTENV_LAST=first',
      'TESTENV_LAST=second',
    ].join('\n') + '\n'
  );
  const out = parseEnvFile(file);
  assert.strictEqual(out.TESTENV_PLAIN, 'hello');
  assert.strictEqual(out.TESTENV_TRIM, 'spaced');
  assert.strictEqual(out.TESTENV_DQ, 'double quoted');
  assert.strictEqual(out.TESTENV_SQ, 'single quoted');
  assert.strictEqual(out.TESTENV_EQ, 'a=b=c', 'split on the first = only');
  assert.strictEqual(out.TESTENV_EMPTY, '');
  assert.strictEqual(out.TESTENV_HALFQ, '"unbalanced', 'unbalanced quote kept literally');
  assert.ok(!('no-equals-sign-at-all' in out), 'line without = is skipped');
  assert.ok(!('' in out), 'line starting with = is skipped');
  assert.strictEqual(out.TESTENV_LAST, 'second', 'last assignment wins');
  assert.ok(!('# a comment line' in out));
});

test('parseEnvFile: CRLF line endings are handled', () => {
  const out = parseEnvFile(fixture('TESTENV_CRLF=value\r\nTESTENV_OTHER=x\r\n'));
  assert.strictEqual(out.TESTENV_CRLF, 'value');
  assert.strictEqual(out.TESTENV_OTHER, 'x');
});

test('parseEnvFile: missing file is a no-op (empty map)', () => {
  assert.deepStrictEqual(parseEnvFile('/definitely/not/here/.env'), {});
});

test('get: real env > .env file > explicit fallback > DEFAULTS', () => {
  const env = createEnv(fixture('TESTENV_PRECEDENCE=fromfile\nLB_PORT=9999\n'));

  delete process.env.TESTENV_PRECEDENCE;
  assert.strictEqual(env.get('TESTENV_PRECEDENCE'), 'fromfile');

  process.env.TESTENV_PRECEDENCE = 'fromenv';
  try {
    assert.strictEqual(env.get('TESTENV_PRECEDENCE'), 'fromenv', 'real env wins');
  } finally {
    delete process.env.TESTENV_PRECEDENCE;
  }

  // Empty-string real env var must NOT shadow the file value.
  process.env.TESTENV_PRECEDENCE = '';
  try {
    assert.strictEqual(env.get('TESTENV_PRECEDENCE'), 'fromfile');
  } finally {
    delete process.env.TESTENV_PRECEDENCE;
  }

  // Key absent everywhere: explicit fallback first, DEFAULTS last.
  delete process.env.TESTENV_ABSENT;
  assert.strictEqual(env.get('TESTENV_ABSENT', 'fallback'), 'fallback');
  assert.strictEqual(env.get('TESTENV_ABSENT'), undefined, 'no fallback, no default');
  delete process.env.STATUS_PORT;
  const noFile = createEnv(fixture(''));
  assert.strictEqual(noFile.get('STATUS_PORT'), DEFAULTS.STATUS_PORT, 'ARCHITECTURE.md default applies');
  assert.strictEqual(env.get('LB_PORT'), '9999', 'file value beats the default');
});

test('getInt: parses ints, falls back to DEFAULTS on garbage', () => {
  const env = createEnv(fixture('TESTENV_NUM=42\nDB_PORT=not-a-number\n'));
  delete process.env.TESTENV_NUM;
  delete process.env.DB_PORT;
  assert.strictEqual(env.getInt('TESTENV_NUM'), 42);
  assert.strictEqual(env.getInt('DB_PORT'), 3306, 'garbage value falls back to the default');
  assert.strictEqual(env.getInt('TESTENV_MISSING', '7'), 7, 'explicit fallback is parsed');
});

test('createEnv default path keeps the original module behavior', () => {
  // The module-level get() reads PROJECT_ROOT/.env; it must agree with an
  // explicitly-constructed instance for a stable key.
  const mod = require('../env');
  const explicit = createEnv(path.join(mod.PROJECT_ROOT, '.env'));
  assert.strictEqual(mod.get('DB_NAME'), explicit.get('DB_NAME'));
});
