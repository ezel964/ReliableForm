<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';

// Probe once; every case skips (not fails) when Redis is unreachable.
$GLOBALS['redis_up'] = false;
try {
    $GLOBALS['redis_up'] = RedisClient::instance()->ping();
} catch (Throwable) {
    $GLOBALS['redis_up'] = false;
}

function redis(): RedisClient
{
    if (!$GLOBALS['redis_up']) {
        skip('Redis PING failed — start it via launch.sh for these cases');
    }
    return RedisClient::instance();
}

const K = 'test:unit:'; // every key this file touches is DEL'd again

t('SET/GET/DEL roundtrip', function (): void {
    $r = redis();
    assert_true($r->set(K . 'k1', 'value-1'));
    assert_eq('value-1', $r->get(K . 'k1'));
    assert_eq(1, $r->del(K . 'k1'));
    assert_eq(null, $r->get(K . 'k1'), 'deleted key reads as null');
});

t('INCR counts from scratch and onward', function (): void {
    $r = redis();
    $r->del(K . 'ctr');
    assert_eq(1, $r->incr(K . 'ctr'));
    assert_eq(2, $r->incr(K . 'ctr'));
    $r->del(K . 'ctr');
});

t('RPUSH/LLEN/LPOP list ops via command()', function (): void {
    $r = redis();
    $r->del(K . 'list');
    assert_eq(2, $r->command('RPUSH', K . 'list', 'a', 'b'));
    assert_eq(2, $r->command('LLEN', K . 'list'));
    assert_eq('a', $r->command('LPOP', K . 'list'), 'FIFO via RPUSH+LPOP');
    assert_eq('b', $r->command('LPOP', K . 'list'));
    assert_eq(null, $r->command('LPOP', K . 'list'), 'empty list pops nil');
    $r->del(K . 'list');
});

t('SETEX sets a TTL; TTL reports it', function (): void {
    $r = redis();
    assert_true($r->setex(K . 'ttl', 30, 'v'));
    $ttl = $r->ttl(K . 'ttl');
    assert_true($ttl >= 1 && $ttl <= 30, "ttl in (0,30], got $ttl");
    $r->del(K . 'ttl');
    assert_true($r->ttl(K . 'ttl') < 0, 'missing key has negative ttl');
});

t('BLPOP on a quiet key returns null in ~the timeout (no socket error)', function (): void {
    $r = redis();
    $r->del(K . 'quiet');
    $start = microtime(true);
    $res = $r->blpop(K . 'quiet', 1);
    $elapsed = microtime(true) - $start;
    assert_eq(null, $res, 'timeout must read as null, not throw');
    assert_true($elapsed >= 0.9, sprintf('returned too early (%.2fs)', $elapsed));
    assert_true($elapsed < 4.0, sprintf('took %.2fs — stream timeout mishandled?', $elapsed));
});

t('BLPOP returns [key, value] when data is waiting', function (): void {
    $r = redis();
    $r->rpush(K . 'ready', 'payload');
    assert_eq([K . 'ready', 'payload'], $r->blpop(K . 'ready', 1));
    $r->del(K . 'ready');
});

t('unicode value roundtrips byte-exact', function (): void {
    $r = redis();
    $v = "héllo wörld 🙂 — \"quoted\"\nnewline";
    assert_true($r->set(K . 'uni', $v));
    assert_eq($v, $r->get(K . 'uni'));
    $r->del(K . 'uni');
});
