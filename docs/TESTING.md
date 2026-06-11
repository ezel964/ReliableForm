# ReliableForm — Testing

Three suites, zero test dependencies (no composer, no npm test packages). All
commands assume the project root as cwd.

See also: [RUNBOOK.md](RUNBOOK.md) (start/stop, drills, logs) ·
[SRE-GUIDE.md](SRE-GUIDE.md) (overhead A/B protocol) ·
[METRICS.md](METRICS.md) (telemetry catalog).

## The suites

| suite | run | needs | exit codes |
|---|---|---|---|
| PHP unit | `bash tests/run-unit.sh` | php only (Redis cases self-skip) | 0 green / 1 any failure |
| Node unit | `cd apps/status && npm test` | node ≥18 | 0 green / 1 any failure |
| e2e gate | `bash tests/e2e.sh [--full] [--keep]` | the whole stack RUNNING (`bash launch.sh`) + mysql/redis-cli/curl | 0 green / 1 any failed check / 2 preflight failed |

## PHP unit suite — `tests/run-unit.sh`

Runs every `tests/unit/test_*.php` with `INSTANCE_ID=unittest` against the
real kernel (`lib/bootstrap.php`, real `RF_ROOT`). Per-test output is
`✓` pass / `✗` fail / `~` skip plus a per-file summary line. No MySQL is ever
touched; Redis-dependent cases **skip** (not fail) when PING fails. Tests
clean up everything they write (spool/log fixtures) — running twice in a row
must leave `storage/` byte-identical.

| file | covers |
|---|---|
| `test_config.php` | `.env` parser: KEY=VALUE, comments, quotes, real-env precedence, missing-file no-op, defaults |
| `test_trace.php` | traceparent parse (valid/garbage/all-zero), `startFromJob`, `traceparent()` format, `context()` shape |
| `test_validation.php` | every builder rule (size cap, count, id regex+uniqueness, type whitelist incl. `file`, labels, options, rating max, webhook_url) + per-type submission rules + file rules via synthetic `$_FILES` arrays |
| `test_pdf.php` | `%PDF-1.4` header, `%%EOF`, byte-exact xref offsets, stream `/Length`, paren/backslash escaping, no raw multibyte |
| `test_otel_shape.php` | golden OTLP spool line (top keys, span keys, typed attributes, status, parentSpanId); disabled ⇒ no writes |
| `test_csv.php` | `csv_cell` RFC-4180 quoting + formula-injection guard, `csv_answer` flattening (helpers live in `apps/web/src/csv.php`) |
| `test_applog.php` | `APPLOG_ENABLED=0` ⇒ silence; line shape (`ts`/`instance`/`event`/`trace_id`); 512-char truncation |
| `test_redisclient.php` | RESP roundtrips, INCR, list ops, TTL, BLPOP timeout semantics, unicode — all `test:unit:*` keys deleted; skips without Redis |

### Adding a unit test

Create `tests/unit/test_<thing>.php`; the runner picks it up by glob:

```php
<?php
declare(strict_types=1);
require __DIR__ . '/_harness.php';   // loads lib/bootstrap.php, defines the helpers

t('what it asserts', function (): void {
    assert_eq('expected', my_fn('input'));     // strict ===, throws on mismatch
    assert_true(1 < 2, 'message on failure');
    assert_match('/^[0-9a-f]{32}$/', $id);
});

t('needs redis', function (): void {
    skip('reason');                            // counts as ~, never as a failure
});
```

`t()` catches every `Throwable`; a file's exit code is non-zero when any of
its tests failed. Delete anything your test writes (the suite must be
re-runnable with no leftovers).

## Node unit suite — `apps/status/test/`

Built-in `node:test` + `node:assert`, zero new dependencies
(`npm test` = `node --test test/*.test.js`).

- `env.test.js` — `.env` parser fixtures in `os.tmpdir()` via
  `createEnv(path)` (env.js takes an optional path; default behavior — and
  every `server.js` call — unchanged): precedence real env > file > fallback >
  defaults, quotes/comments/malformed lines, CRLF, getInt fallbacks.
- `resp.test.js` — drives `redis.js` against an in-process `net` server
  speaking canned RESP: simple string, bulk (incl. multibyte byte-lengths),
  nil, integer, error ⇒ rejected promise, arrays (empty/nil/nested),
  pipelining order, replies split across TCP chunks.

## e2e gate — `tests/e2e.sh`

THE regression gate. Requires a healthy running stack (preflight probes
`/api/status`, the demo API key and the mysql/redis CLIs; exits **2** with
"bash launch.sh first" guidance otherwise). TAP-ish output
(`ok N - …` / `not ok N - …`), summary, exit 1 on any failure. ~10s.

What it covers, in order: register→logout→login journey (CSRF flow) with a
throwaway `e2e+<epoch>@test.local` user · builder form create (text/email/
select/rating/file + webhook to a self-started `tools/webhook-sink.php` on
:9444) · happy-path multipart submit with a generated traceparent (PDF done +
file, email sent + `.eml`, webhook delivered attempts=1, **trace id observed
at the sink**, upload stored + referenced) · deterministic webhook retry
(`?fail_first=1` via mysql UPDATE + `DEL cache:form:<id>`, attempts=2) ·
validation negatives (missing required ⇒ 200 re-render, 3MB upload direct to
web1 ⇒ friendly error, zero rows) · API v1 (me/forms/submission 201+422/401,
**no Set-Cookie on any v1 response**) · CSV export (labels + formula guard) ·
chaos drill (`launch.sh kill web1`, LB absorbs 6/6, status notices, restart
heals) · trace join (submission #1's trace id in ≥3 `app-*.jsonl` files) ·
cleanup (throwaway form via web UI cascade, user + uploads dir, the one
API-created demo-form submission, sink, temp files; `queue:dead` asserted
unchanged; demo data untouched).

Flags:

- `--full` — adds the API rate-limit check: 130× `/v1/me` expecting ≥1 429.
  **This locks the demo key for ~60s**; cleanup DELs `ratelimit:api:<key>` so
  the key works again immediately. Off by default — don't use it while
  anything else (loadgen, a colleague) shares the demo key.
- `--keep` — skip data/temp cleanup for debugging (prints what was left
  where). The sink process is still killed.

## CI notes

- Order: PHP unit + Node unit first — they are stack-free (no MySQL; Redis
  optional, cases skip without it). Then `bash launch.sh` and the e2e gate.
- The unit runner and e2e are bash 3.2-safe and color via
  `scripts/common.sh` (`NO_COLOR=1` or a non-tty disables colors).
- e2e exit 2 means "environment not ready", not "regression" — gate CI on
  exit 1 vs 2 accordingly.
- Repeat-safe: unit suite leaves no artifacts; e2e deletes everything it
  created (run it twice back-to-back as a smoke check of that invariant).
