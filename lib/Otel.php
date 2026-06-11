<?php

declare(strict_types=1);

/**
 * Minimal OpenTelemetry tracing — no SDK, no composer, sidecar-shipped.
 *
 * App processes never speak HTTP for telemetry: span() buffers, flush()
 * appends one JSON line to storage/run/otel-spool/<instance>.jsonl (O_APPEND,
 * microseconds). The otel shipper sidecar (apps/workers/otel_shipper.php)
 * batches spool lines into OTLP/HTTP JSON and POSTs them to
 * OTEL_EXPORTER_OTLP_ENDPOINT/v1/traces. This keeps export cost out of the
 * request path entirely — which is the point of the playground: measuring
 * instrumentation overhead without the exporter polluting the numbers.
 *
 * Disabled (no buffering, no writes) unless OTEL_EXPORTER_OTLP_ENDPOINT is set.
 *
 * Spool line format (consumed by the shipper, language-agnostic — the Node
 * status service writes the same shape):
 *   {"service":"reliableform-web","instance":"web1","spans":[<OTLP span>, ...]}
 */
final class Otel
{
    public const KIND_SERVER = 2;
    public const KIND_CLIENT = 3;
    public const KIND_PRODUCER = 4;
    public const KIND_CONSUMER = 5;

    /** @var list<array<string, mixed>> */
    private static array $buffer = [];

    public static function enabled(): bool
    {
        $endpoint = Config::get('OTEL_EXPORTER_OTLP_ENDPOINT');
        return $endpoint !== null && $endpoint !== '';
    }

    /**
     * Record one finished span (OTLP/JSON protobuf mapping: kind as enum
     * number, times as uint64 strings, attributes as key/typed-value pairs).
     * Defaults to the current Trace context; pass explicit ids for child
     * spans (e.g. a webhook CLIENT span under the job's CONSUMER span).
     *
     * @param array<string, string|int|float|bool> $attributes
     */
    public static function span(
        string $name,
        float $startMicrotime,
        float $endMicrotime,
        int $kind,
        array $attributes = [],
        bool $error = false,
        ?string $spanId = null,
        ?string $parentSpanId = null
    ): void {
        if (!self::enabled()) {
            return;
        }
        $attrs = [];
        foreach ($attributes as $k => $v) {
            $attrs[] = ['key' => (string) $k, 'value' => self::attrValue($v)];
        }
        $span = [
            'traceId' => Trace::id(),
            'spanId' => $spanId ?? Trace::spanId(),
            'name' => $name,
            'kind' => $kind,
            'startTimeUnixNano' => sprintf('%.0f', $startMicrotime * 1e9),
            'endTimeUnixNano' => sprintf('%.0f', $endMicrotime * 1e9),
            'attributes' => $attrs,
            'status' => $error ? ['code' => 2] : ['code' => 0],
        ];
        $parent = $parentSpanId ?? Trace::parentSpanId();
        if ($parent !== null) {
            $span['parentSpanId'] = $parent;
        }
        self::$buffer[] = $span;
    }

    /**
     * Append buffered spans to the spool. Web requests call this once at
     * shutdown; workers call it after every job (their process never exits).
     * Never throws — telemetry must not break the request/job.
     */
    public static function flush(): void
    {
        if (self::$buffer === []) {
            return;
        }
        $spans = self::$buffer;
        self::$buffer = [];
        try {
            $line = json_encode([
                'service' => self::serviceName(),
                'instance' => instance_id(),
                'spans' => $spans,
            ], JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                return;
            }
            $dir = RF_ROOT . '/storage/run/otel-spool';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($dir . '/' . instance_id() . '.jsonl', $line . "\n", FILE_APPEND);
        } catch (Throwable) {
            // swallow — see contract
        }
    }

    /** reliableform-web for web1/web2, otherwise reliableform-<instance>. */
    public static function serviceName(): string
    {
        $override = Config::get('OTEL_SERVICE_NAME');
        if ($override !== null && $override !== '') {
            return $override;
        }
        $instance = instance_id();
        if (str_starts_with($instance, 'web')) {
            return 'reliableform-web';
        }
        return 'reliableform-' . $instance;
    }

    /** @param string|int|float|bool $v */
    private static function attrValue($v): array
    {
        if (is_bool($v)) {
            return ['boolValue' => $v];
        }
        if (is_int($v)) {
            return ['intValue' => (string) $v]; // OTLP/JSON: int64 as string
        }
        if (is_float($v)) {
            return ['doubleValue' => $v];
        }
        return ['stringValue' => (string) $v];
    }
}
