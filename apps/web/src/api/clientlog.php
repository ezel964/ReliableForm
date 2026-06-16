<?php

declare(strict_types=1);

/**
 * POST /v1/clientlog — browser JS error / unhandledrejection sink. Sessionless
 * beacon: no auth, no cookie, returns 204. Per-IP rate limited (fail-open).
 * Records a structured client_error event and bumps web.client.error.
 */

http_response_code(204);
header_remove('Content-Type');

if (Config::get('CLIENTLOG_ENABLED', '1') !== '1') {
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    Metrics::increment('web.client.clientlog_malformed');
    exit;
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
if (!clientlog_rate_ok($ip)) {
    Metrics::increment('web.client.clientlog_ratelimited');
    exit;
}

AppLog::event('client_error', clientlog_fields($payload, (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
Metrics::increment('web.client.error');
exit;
