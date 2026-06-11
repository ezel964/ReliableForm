<?php

declare(strict_types=1);

/**
 * Fire-and-forget statsd over UDP. This is the hook for the SLI library:
 * point STATSD_HOST/STATSD_PORT at any statsd-compatible listener.
 * Never throws, never blocks meaningfully; silently no-ops on failure.
 */
final class Metrics
{
    /** @var resource|null */
    private static $sock = null;
    private static bool $disabled = false;

    public static function increment(string $metric, int $value = 1): void
    {
        self::send(sprintf('reliableform.%s:%d|c', $metric, $value));
    }

    public static function timing(string $metric, float $ms): void
    {
        self::send(sprintf('reliableform.%s:%.3f|ms', $metric, $ms));
    }

    public static function gauge(string $metric, float $value): void
    {
        self::send(sprintf('reliableform.%s:%.3f|g', $metric, $value));
    }

    private static function send(string $payload): void
    {
        if (self::$disabled) {
            return;
        }
        try {
            if (self::$sock === null) {
                $sock = @fsockopen(
                    'udp://' . Config::get('STATSD_HOST', '127.0.0.1'),
                    (int) Config::get('STATSD_PORT', '8125'),
                    $errno,
                    $errstr,
                    0.2
                );
                if ($sock === false) {
                    self::$disabled = true;
                    return;
                }
                self::$sock = $sock;
            }
            @fwrite(self::$sock, $payload);
        } catch (Throwable) {
            self::$disabled = true;
        }
    }
}
