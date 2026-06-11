<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';

/**
 * GearmanLite protocol roundtrips against the live gearmand. Every case
 * skips (not fails) when gearmand is unreachable — mirror of
 * test_redisclient.php. All jobs ride throwaway rf_unittest_* functions
 * (never the real rf_pdf/rf_email/rf_webhook), and every case drains what
 * it queued, so a running stack is never disturbed.
 */

// Probe once; every case skips when gearmand is unreachable.
$GLOBALS['gearman_up'] = false;
try {
    $probe = new GearmanLite();
    $probe->connect();
    $GLOBALS['gearman_up'] = $probe->ping();
    $probe->close();
} catch (Throwable) {
    $GLOBALS['gearman_up'] = false;
}

// Per-run function namespace: stale queue contents from an aborted previous
// run can never bleed into this one.
define('GM_FN', 'rf_unittest_' . getmypid() . '_' . bin2hex(random_bytes(3)));

function gm(): GearmanLite
{
    if (!$GLOBALS['gearman_up']) {
        skip('gearmand unreachable — install gearman + bash launch.sh for these cases');
    }
    $c = new GearmanLite();
    $c->connect();
    return $c;
}

/** Register + grab/complete until idle; returns how many jobs were drained. */
function gm_drain(string $function, int $expectAtMost = 10): int
{
    $w = gm();
    $w->register($function);
    $n = 0;
    while ($n < $expectAtMost && ($job = $w->grab(1)) !== null) {
        $w->complete($job['handle']);
        $n++;
    }
    $w->close();
    return $n;
}

t('ping() roundtrips ECHO_REQ/ECHO_RES', function (): void {
    $c = gm();
    assert_true($c->ping(), 'ping must echo back over the binary protocol');
    $c->close();
});

t('submitBackground returns a handle; grab delivers the payload byte-exact', function (): void {
    $fn = GM_FN . '_echo';
    $payload = "héllo wörld 🙂 \0 embedded NUL + \"json\"";
    $client = gm();
    $handle = $client->submitBackground($fn, $payload, 'uq-echo-' . getmypid());
    assert_true($handle !== '', 'JOB_CREATED must carry a non-empty handle');

    $worker = gm();
    $worker->register($fn);
    $job = $worker->grab(3);
    assert_true($job !== null, 'worker must be assigned the queued job');
    assert_eq($fn, $job['function']);
    assert_eq($handle, $job['handle'], 'JOB_ASSIGN handle equals the JOB_CREATED handle');
    assert_eq($payload, $job['payload'], 'payload must roundtrip byte-exact (incl. NUL)');
    $worker->complete($job['handle']);
    $worker->close();
    $client->close();
});

t('grab on an idle function returns null in ~idleTimeout (NO_JOB → PRE_SLEEP path)', function (): void {
    $w = gm();
    $w->register(GM_FN . '_idle');
    $start = microtime(true);
    $job = $w->grab(1);
    $elapsed = microtime(true) - $start;
    assert_eq(null, $job, 'idle timeout must read as null, not throw');
    assert_true($elapsed >= 0.9, sprintf('returned too early (%.2fs)', $elapsed));
    assert_true($elapsed < 4.0, sprintf('took %.2fs — PRE_SLEEP timeout mishandled?', $elapsed));
    $w->close();
});

t('unique-id dedup: same unique twice with no worker attached ⇒ 1 queued', function (): void {
    $fn = GM_FN . '_dedup';
    $unique = 'uq-dedup-' . getmypid();
    $c = gm();
    $c->submitBackground($fn, '{"n":1}', $unique);
    $c->submitBackground($fn, '{"n":2}', $unique);
    $status = $c->status();
    assert_true(isset($status[$fn]), 'function must appear in admin status');
    assert_eq(1, $status[$fn]['queued'], 'duplicate unique must coalesce to 1 queued job');
    $c->close();
    assert_eq(1, gm_drain($fn), 'exactly one job must drain');
});

t('attempt-suffixed uniques do NOT coalesce (retry re-push semantics)', function (): void {
    $fn = GM_FN . '_retry';
    $c = gm();
    $c->submitBackground($fn, '{"attempt":0}', 'uq-retry-' . getmypid() . '-a0');
    $c->submitBackground($fn, '{"attempt":1}', 'uq-retry-' . getmypid() . '-a1');
    $status = $c->status();
    assert_eq(2, $status[$fn]['queued'] ?? -1, 'distinct uniques must both queue');
    $c->close();
    assert_eq(2, gm_drain($fn), 'both jobs must drain');
});

t('status() parses queued/running/workers as ints and reflects the drain', function (): void {
    $fn = GM_FN . '_status';
    $c = gm();
    $c->submitBackground($fn, '{}', 'uq-status-' . getmypid());
    $status = $c->status();
    assert_true(isset($status[$fn]), 'queued function listed by status');
    assert_eq(1, $status[$fn]['queued']);
    assert_eq(0, $status[$fn]['running']);
    assert_eq(0, $status[$fn]['workers'], 'no worker is registered for it yet');
    assert_eq(1, gm_drain($fn));
    $after = $c->status();
    assert_eq(0, $after[$fn]['queued'] ?? 0, 'drained function reads 0 queued');
    $c->close();
});

t('admin status text and binary packets interleave on one connection', function (): void {
    $c = gm();
    $first = $c->status();      // text
    assert_true($c->ping());    // binary
    $second = $c->status();     // text again
    assert_true(is_array($first) && is_array($second));
    $c->close();
});
