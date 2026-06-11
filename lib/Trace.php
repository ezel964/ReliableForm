<?php

declare(strict_types=1);

/**
 * W3C Trace Context (traceparent) — carried across the whole stack:
 * nginx → web request → queue job payload → worker, and outward on
 * webhook deliveries. No SDK; just ids and the header format.
 */
final class Trace
{
    private static ?string $traceId = null;
    private static ?string $spanId = null;
    private static ?string $parentSpanId = null;

    /** Adopt an incoming traceparent header, or start a fresh trace. */
    public static function start(?string $traceparent = null): void
    {
        self::$parentSpanId = null;
        if ($traceparent !== null
            && preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', strtolower(trim($traceparent)), $m)
            && $m[1] !== str_repeat('0', 32)
            && $m[2] !== str_repeat('0', 16)
        ) {
            self::$traceId = $m[1];
            self::$parentSpanId = $m[2];
        } else {
            self::$traceId = self::hex(16);
        }
        self::$spanId = self::hex(8);
    }

    /** Continue the trace a producer embedded in a queue job payload. */
    public static function startFromJob(array $job): void
    {
        $trace = $job['trace'] ?? null;
        $traceId = is_array($trace) ? ($trace['trace_id'] ?? '') : '';
        $parent = is_array($trace) ? ($trace['span_id'] ?? '') : '';
        if (is_string($traceId) && preg_match('/^[0-9a-f]{32}$/', $traceId)) {
            self::$traceId = $traceId;
            self::$parentSpanId = (is_string($parent) && preg_match('/^[0-9a-f]{16}$/', $parent)) ? $parent : null;
        } else {
            self::$traceId = self::hex(16);
            self::$parentSpanId = null;
        }
        self::$spanId = self::hex(8);
    }

    public static function id(): string
    {
        if (self::$traceId === null) {
            self::start();
        }
        return self::$traceId;
    }

    public static function spanId(): string
    {
        if (self::$spanId === null) {
            self::start();
        }
        return self::$spanId;
    }

    public static function parentSpanId(): ?string
    {
        return self::$parentSpanId;
    }

    /** Outgoing header value (always sampled — this is a lab, capture everything). */
    public static function traceparent(): string
    {
        return '00-' . self::id() . '-' . self::spanId() . '-01';
    }

    /** Embed in queue job payloads; workers resume via startFromJob(). */
    public static function context(): array
    {
        return ['trace_id' => self::id(), 'span_id' => self::spanId()];
    }

    private static function hex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
