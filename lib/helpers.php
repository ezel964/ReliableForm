<?php

declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 302);
    exit;
}

/** @param array<mixed> $data */
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function flash(string $type, string $msg): void
{
    Session::start();
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg];
}

/** @return list<array{type: string, msg: string}> */
function flashes(): array
{
    Session::start();
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/** @param array<string, mixed> $vars */
function render(string $view, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    include RF_ROOT . '/apps/web/src/views/' . $view . '.php';
    return (string) ob_get_clean();
}

/** Best-effort liveness signal; never throws (Redis being down must not kill the caller). */
function heartbeat(string $instance): void
{
    try {
        RedisClient::instance()->setex('heartbeat:' . $instance, 15, (string) time());
    } catch (Throwable) {
        // ignore — the status page will report the stale heartbeat
    }
}

/**
 * Sleep in 1s ticks, heartbeating each tick, so worker backoff sleeps can
 * never outlive the 15s heartbeat TTL and read as "worker down".
 */
function sleep_with_heartbeat(int $seconds, string $instance): void
{
    for ($i = 0; $i < $seconds; $i++) {
        heartbeat($instance);
        sleep(1);
    }
    heartbeat($instance);
}

/** 40 hex chars — fits users.api_key CHAR(40). */
function generate_api_key(): string
{
    return bin2hex(random_bytes(20));
}

function public_id(): string
{
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $s = '';
    for ($i = 0; $i < 10; $i++) {
        $s .= $chars[random_int(0, 35)];
    }
    return $s;
}

function request_ip(): string
{
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd !== '') {
        $first = trim(explode(',', $fwd)[0]);
        if ($first !== '') {
            return $first;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

function instance_id(): string
{
    $id = getenv('INSTANCE_ID');
    return ($id !== false && $id !== '') ? $id : 'unknown';
}
