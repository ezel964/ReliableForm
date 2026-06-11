<?php

declare(strict_types=1);

// Health endpoint: JSON only, no layout, never starts a session.
$dbOk = false;
$redisOk = false;

try {
    $dbOk = DB::one('SELECT 1') !== null;
} catch (Throwable) {
    $dbOk = false;
}

try {
    $redisOk = RedisClient::instance()->ping();
} catch (Throwable) {
    $redisOk = false;
}

$ok = $dbOk && $redisOk;

json_response([
    'ok' => $ok,
    'instance' => instance_id(),
    'checks' => ['db' => $dbOk, 'redis' => $redisOk],
    'time' => time(),
], $ok ? 200 : 503);
