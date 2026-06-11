<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';

const TP_TRACE = 'aabbccddeeff00112233445566778899';
const TP_SPAN = '0123456789abcdef';

t('start() adopts a valid traceparent', function (): void {
    Trace::start('00-' . TP_TRACE . '-' . TP_SPAN . '-01');
    assert_eq(TP_TRACE, Trace::id());
    assert_eq(TP_SPAN, Trace::parentSpanId(), 'incoming span id becomes the parent');
    assert_match('/^[0-9a-f]{16}$/', Trace::spanId());
    assert_true(Trace::spanId() !== TP_SPAN, 'fresh span id, not the parent');
});

t('start() lowercases and trims the incoming header', function (): void {
    Trace::start("  00-" . strtoupper(TP_TRACE) . '-' . strtoupper(TP_SPAN) . "-FF \n");
    assert_eq(TP_TRACE, Trace::id());
    assert_eq(TP_SPAN, Trace::parentSpanId());
});

t('start() rejects garbage and starts a fresh trace', function (): void {
    foreach (['garbage', '00-zz-yy-01', '01-' . TP_TRACE . '-' . TP_SPAN . '-01', ''] as $bad) {
        Trace::start($bad);
        assert_match('/^[0-9a-f]{32}$/', Trace::id());
        assert_true(Trace::id() !== TP_TRACE, 'must not adopt from garbage');
        assert_eq(null, Trace::parentSpanId());
    }
});

t('start() rejects the all-zero trace id', function (): void {
    Trace::start('00-' . str_repeat('0', 32) . '-' . TP_SPAN . '-01');
    assert_true(Trace::id() !== str_repeat('0', 32));
    assert_eq(null, Trace::parentSpanId());
});

t('start() rejects the all-zero span id', function (): void {
    Trace::start('00-' . TP_TRACE . '-' . str_repeat('0', 16) . '-01');
    assert_true(Trace::id() !== TP_TRACE, 'whole header is invalid when span id is zero');
    assert_eq(null, Trace::parentSpanId());
});

t('startFromJob() resumes a valid embedded context', function (): void {
    Trace::startFromJob(['trace' => ['trace_id' => TP_TRACE, 'span_id' => TP_SPAN]]);
    assert_eq(TP_TRACE, Trace::id());
    assert_eq(TP_SPAN, Trace::parentSpanId());
    assert_match('/^[0-9a-f]{16}$/', Trace::spanId());
});

t('startFromJob() with missing/invalid trace starts fresh', function (): void {
    foreach ([[], ['trace' => 'nope'], ['trace' => ['trace_id' => 'short']]] as $job) {
        Trace::startFromJob($job);
        assert_match('/^[0-9a-f]{32}$/', Trace::id());
        assert_true(Trace::id() !== TP_TRACE);
        assert_eq(null, Trace::parentSpanId());
    }
});

t('startFromJob() tolerates a bad span id but keeps the trace id', function (): void {
    Trace::startFromJob(['trace' => ['trace_id' => TP_TRACE, 'span_id' => 'XYZ']]);
    assert_eq(TP_TRACE, Trace::id());
    assert_eq(null, Trace::parentSpanId());
});

t('traceparent() has the W3C format and embeds the current ids', function (): void {
    Trace::start('00-' . TP_TRACE . '-' . TP_SPAN . '-01');
    $tp = Trace::traceparent();
    assert_match('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $tp);
    assert_eq('00-' . Trace::id() . '-' . Trace::spanId() . '-01', $tp);
});

t('context() is exactly {trace_id, span_id} of the live trace', function (): void {
    Trace::start('00-' . TP_TRACE . '-' . TP_SPAN . '-01');
    $ctx = Trace::context();
    assert_eq(['trace_id', 'span_id'], array_keys($ctx));
    assert_eq(TP_TRACE, $ctx['trace_id']);
    assert_eq(Trace::spanId(), $ctx['span_id']);
});
