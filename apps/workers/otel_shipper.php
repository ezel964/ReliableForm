<?php

declare(strict_types=1);

/**
 * OTel shipper sidecar: turns spool lines written by Otel::flush() (PHP) and
 * otel.js (Node) into OTLP/HTTP JSON POSTs to the collector.
 *
 * Loop (every ~2s):
 *   1. heartbeat
 *   2. for each storage/run/otel-spool/<instance>.jsonl:
 *      rename → <name>.shipping.<ts> (atomic handoff; writers just create a
 *      fresh spool file on their next append)
 *   3. for each *.shipping.* file: parse lines, group by (service, instance),
 *      POST one OTLP payload; delete the file on success, leave it for retry
 *      on failure, DROP it when older than 120s (count otel.spans_dropped).
 *
 * Exits immediately when OTEL_EXPORTER_OTLP_ENDPOINT is not configured —
 * launch.sh treats that as "disabled", not a failure.
 */

require __DIR__ . '/../../lib/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (!Otel::enabled()) {
    echo "otel shipper: OTEL_EXPORTER_OTLP_ENDPOINT not set — nothing to do, exiting\n";
    exit(0);
}

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running): void {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

const SPOOL_DIR_REL = '/storage/run/otel-spool';
const SHIPPING_MAX_AGE_S = 120;

echo 'otel shipper started (instance ' . instance_id() . ') → '
    . Config::get('OTEL_EXPORTER_OTLP_ENDPOINT') . "\n";
heartbeat(instance_id());

/** @return array<string, mixed>|null */
function ship_post(string $url, string $payload): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
        CURLOPT_TIMEOUT_MS => 2000,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $body !== false && $code >= 200 && $code < 300;
}

/** Group spool lines by service+instance and POST one payload per group. */
function ship_file(string $path, string $endpoint): bool
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return true; // unreadable: treat as consumed, the age cap will remove it
    }
    $groups = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (!is_array($row) || !isset($row['spans']) || !is_array($row['spans'])) {
            continue;
        }
        $key = (string) ($row['service'] ?? 'reliableform') . "\0" . (string) ($row['instance'] ?? 'unknown');
        foreach ($row['spans'] as $span) {
            $groups[$key][] = $span;
        }
    }
    foreach ($groups as $key => $spans) {
        [$service, $instance] = explode("\0", $key, 2);
        $payload = json_encode([
            'resourceSpans' => [[
                'resource' => ['attributes' => [
                    ['key' => 'service.name', 'value' => ['stringValue' => $service]],
                    ['key' => 'service.instance.id', 'value' => ['stringValue' => $instance]],
                ]],
                'scopeSpans' => [[
                    'scope' => ['name' => 'reliableform', 'version' => '1.0'],
                    'spans' => $spans,
                ]],
            ]],
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            continue;
        }
        if (!ship_post($endpoint . '/v1/traces', $payload)) {
            return false; // leave the file for retry; age cap bounds it
        }
        Metrics::increment('otel.spans_exported', count($spans));
    }
    return true;
}

$spoolDir = RF_ROOT . SPOOL_DIR_REL;
$endpoint = rtrim((string) Config::get('OTEL_EXPORTER_OTLP_ENDPOINT'), '/');

while ($running) {
    heartbeat(instance_id());
    try {
        if (is_dir($spoolDir)) {
            // 1) take over fresh spool files
            foreach (glob($spoolDir . '/*.jsonl') ?: [] as $file) {
                @rename($file, $file . '.shipping.' . time());
            }
            // 2) ship (and retry previously failed) handoff files
            foreach (glob($spoolDir . '/*.shipping.*') ?: [] as $file) {
                $age = time() - (int) substr((string) strrchr($file, '.'), 1);
                if (ship_file($file, $endpoint)) {
                    @unlink($file);
                } elseif ($age > SHIPPING_MAX_AGE_S) {
                    $dropped = count(@file($file, FILE_SKIP_EMPTY_LINES) ?: []);
                    Metrics::increment('otel.spans_dropped', max(1, $dropped));
                    error_log('otel shipper: collector unreachable for ' . $age . 's — dropping ' . basename($file));
                    @unlink($file);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('otel shipper: ' . $e->getMessage());
    }
    // 2s cadence, sliced so SIGTERM lands quickly
    for ($i = 0; $i < 4 && $running; $i++) {
        usleep(500000);
    }
}

echo "otel shipper stopping\n";
exit(0);
