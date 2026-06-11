<?php

declare(strict_types=1);

/**
 * Local webhook sink for testing worker-webhook deliveries.
 *
 *   php -S 127.0.0.1:9444 tools/webhook-sink.php
 *
 * POST anywhere      → 200 {"ok":true}
 * POST ...?fail=1    → always 500 {"ok":false}
 * POST ...?fail_first=N → 500 for the first N attempts of each delivery id
 *                      (counter in /tmp/rf-sink-<delivery id>.count), then 200
 * GET /              → this usage page
 *
 * Logs one stdout line per POST: ts, delivery id, submission id, attempt,
 * served status, and the incoming traceparent header (trace propagation proof).
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><title>ReliableForm webhook sink</title></head><body>'
        . '<h1>ReliableForm webhook sink</h1>'
        . '<p>POST anything here; one stdout line is logged per delivery.</p>'
        . '<ul>'
        . '<li><code>POST /hook</code> → 200 <code>{"ok":true}</code></li>'
        . '<li><code>POST /hook?fail=1</code> → always 500</li>'
        . '<li><code>POST /hook?fail_first=N</code> → 500 for the first N attempts per '
        . 'X-ReliableForm-Delivery id (state in /tmp/rf-sink-&lt;id&gt;.count), then 200</li>'
        . '</ul>'
        . '<p>Run: <code>php -S 127.0.0.1:9444 tools/webhook-sink.php</code></p>'
        . '</body></html>';
    exit;
}

$deliveryId = $_SERVER['HTTP_X_RELIABLEFORM_DELIVERY'] ?? '-';
$traceparent = $_SERVER['HTTP_TRACEPARENT'] ?? '-';

$body = (string) file_get_contents('php://input');
$json = json_decode($body, true);
$submissionId = is_array($json) ? ($json['submission']['id'] ?? '-') : '-';
$attempt = is_array($json) ? ($json['delivery']['attempt'] ?? '-') : '-';

$status = 200;
if (($_GET['fail'] ?? '') === '1') {
    $status = 500;
} elseif (ctype_digit((string) ($_GET['fail_first'] ?? ''))) {
    $n = (int) $_GET['fail_first'];
    $file = '/tmp/rf-sink-' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $deliveryId) . '.count';
    $seen = (int) @file_get_contents($file);
    if ($seen < $n) {
        $status = 500;
        @file_put_contents($file, (string) ($seen + 1));
    }
}

http_response_code($status);
header('Content-Type: application/json');
echo $status === 200 ? '{"ok":true}' : '{"ok":false}';

// One line per request to the server process' stdout (not the HTTP response).
file_put_contents('php://stdout', sprintf(
    "[%s] delivery=%s submission=%s attempt=%s served=%d traceparent=%s\n",
    gmdate('Y-m-d\TH:i:s\Z'),
    $deliveryId,
    is_scalar($submissionId) ? (string) $submissionId : '?',
    is_scalar($attempt) ? (string) $attempt : '?',
    $status,
    $traceparent
));
