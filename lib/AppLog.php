<?php

declare(strict_types=1);

/**
 * Structured JSON event log — one line per web request / worker job, written
 * to storage/logs/app-<instance>.jsonl. Per-instance files + single-line
 * O_APPEND writes keep concurrent processes from interleaving. Never throws.
 */
final class AppLog
{
    public static function enabled(): bool
    {
        return Config::get('APPLOG_ENABLED', '1') !== '0';
    }

    /** @param array<string, mixed> $fields */
    public static function event(string $event, array $fields = []): void
    {
        if (!self::enabled()) {
            return;
        }
        try {
            // Cap string fields so a line stays far below the 8KB write
            // atomicity boundary (PHP stream chunk size) — long lines from
            // concurrent php -S workers could otherwise interleave.
            foreach ($fields as $k => $v) {
                if (is_string($v) && strlen($v) > 512) {
                    $fields[$k] = substr($v, 0, 512) . '…';
                }
            }
            $now = microtime(true);
            $line = json_encode(array_merge([
                'ts' => gmdate('Y-m-d\TH:i:s', (int) $now)
                    . sprintf('.%03dZ', (int) round(($now - floor($now)) * 1000)),
                'instance' => instance_id(),
                'event' => $event,
                'trace_id' => Trace::id(),
            ], $fields), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($line === false) {
                return;
            }
            @file_put_contents(
                RF_ROOT . '/storage/logs/app-' . instance_id() . '.jsonl',
                $line . "\n",
                FILE_APPEND
            );
        } catch (Throwable) {
            // observability must never break the request/job
        }
    }
}
