<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';

const OTEL_SPOOL = RF_ROOT . '/storage/run/otel-spool/unittest.jsonl';
const OTEL_TRACE = 'aabbccddeeff00112233445566778899';
const OTEL_PARENT = '0123456789abcdef';

@unlink(OTEL_SPOOL); // never inherit state from a previous (crashed) run

t('endpoint unset ⇒ flush writes nothing', function (): void {
    putenv('OTEL_EXPORTER_OTLP_ENDPOINT'); // unset; .env has no endpoint either
    assert_true(!Otel::enabled(), 'tracing must be disabled without an endpoint');
    Trace::start();
    Otel::span('disabled span', microtime(true) - 0.01, microtime(true), Otel::KIND_SERVER);
    Otel::flush();
    assert_true(!is_file(OTEL_SPOOL), 'no spool file may be written when disabled');
});

t('golden OTLP spool line shape', function (): void {
    putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:1');
    assert_true(Otel::enabled());

    Trace::start('00-' . OTEL_TRACE . '-' . OTEL_PARENT . '-01');
    $t0 = 1750000000.123456;
    $t1 = 1750000000.623456;
    Otel::span('GET /unit/{test}', $t0, $t1, Otel::KIND_SERVER, [
        'str.attr' => 'value-x',
        'int.attr' => 42,
        'bool.attr' => true,
    ], false);
    Otel::flush();

    assert_true(is_file(OTEL_SPOOL), 'spool file written');
    $lines = file(OTEL_SPOOL, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    assert_eq(1, count($lines), 'exactly one spool line per flush');
    $doc = json_decode($lines[0], true);
    assert_true(is_array($doc), 'spool line is valid JSON');

    // Top level: EXACTLY service / instance / spans.
    assert_eq(['service', 'instance', 'spans'], array_keys($doc));
    assert_eq('reliableform-unittest', $doc['service']);
    assert_eq('unittest', $doc['instance']);
    assert_eq(1, count($doc['spans']));

    $span = $doc['spans'][0];
    assert_eq(
        ['traceId', 'spanId', 'name', 'kind', 'startTimeUnixNano', 'endTimeUnixNano', 'attributes', 'status', 'parentSpanId'],
        array_keys($span)
    );
    assert_eq(OTEL_TRACE, $span['traceId']);
    assert_match('/^[0-9a-f]{32}$/', $span['traceId'], 'traceId 32 lowercase hex');
    assert_match('/^[0-9a-f]{16}$/', $span['spanId']);
    assert_eq(OTEL_PARENT, $span['parentSpanId'], 'SERVER span parented to the caller');
    assert_eq('GET /unit/{test}', $span['name']);
    assert_eq(Otel::KIND_SERVER, $span['kind']);
    assert_true(is_int($span['kind']), 'kind is the OTLP enum int');
    assert_true(is_string($span['startTimeUnixNano']), 'uint64 nanos as string');
    assert_match('/^\d+$/', $span['startTimeUnixNano']);
    assert_match('/^\d+$/', $span['endTimeUnixNano']);
    // Float→nano conversion is double-precision; assert to the microsecond.
    assert_true(abs((float) $span['startTimeUnixNano'] / 1e9 - $t0) < 1e-5, 'start nanos ≈ start microtime');
    assert_true(abs((float) $span['endTimeUnixNano'] / 1e9 - $t1) < 1e-5, 'end nanos ≈ end microtime');
    assert_eq(['code' => 0], $span['status']);

    // Attributes: list of {key, value:{stringValue|intValue-as-string|boolValue}}.
    assert_eq([
        ['key' => 'str.attr', 'value' => ['stringValue' => 'value-x']],
        ['key' => 'int.attr', 'value' => ['intValue' => '42']],
        ['key' => 'bool.attr', 'value' => ['boolValue' => true]],
    ], $span['attributes']);
});

t('error spans carry status.code 2; flush drains the buffer', function (): void {
    putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:1');
    @unlink(OTEL_SPOOL);
    Trace::start();
    Otel::span('boom', microtime(true) - 0.01, microtime(true), Otel::KIND_CONSUMER, [], true);
    Otel::flush();
    $doc = json_decode((string) file_get_contents(OTEL_SPOOL), true);
    assert_eq(['code' => 2], $doc['spans'][0]['status']);
    assert_eq(Otel::KIND_CONSUMER, $doc['spans'][0]['kind']);
    @unlink(OTEL_SPOOL);
    Otel::flush(); // buffer already drained — must not write again
    assert_true(!is_file(OTEL_SPOOL), 'second flush with empty buffer writes nothing');
});

putenv('OTEL_EXPORTER_OTLP_ENDPOINT'); // leave the env as found
@unlink(OTEL_SPOOL);                   // leave the spool dir as found
