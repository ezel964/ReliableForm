<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';
require RF_ROOT . '/apps/web/src/api/support.php';

t('error beacon normalises into AppLog fields', function (): void {
    $fields = clientlog_fields([
        'kind' => 'error',
        'message' => 'boom',
        'stack' => 'at x',
        'page' => 'builder',
        'url' => 'http://localhost:8080/forms/new',
        'line' => 12,
        'col' => 5,
    ], 'Mozilla/5.0');
    assert_eq('error', $fields['kind']);
    assert_eq('builder', $fields['page']);
    assert_eq('boom', $fields['message']);
    assert_eq('at x', $fields['stack']);
    assert_eq('http://localhost:8080/forms/new', $fields['url']);
    assert_eq(12, $fields['line']);
    assert_eq(5, $fields['col']);
    assert_eq('Mozilla/5.0', $fields['ua']);
});

t('unhandledrejection kind is preserved; unknown kind falls back to error', function (): void {
    assert_eq('unhandledrejection', clientlog_fields(['kind' => 'unhandledrejection'], '')['kind']);
    assert_eq('error', clientlog_fields(['kind' => 'whoops'], '')['kind']);
    assert_eq('error', clientlog_fields([], '')['kind']);
});

t('page is reduced to a safe token; junk becomes "unknown"', function (): void {
    assert_eq('dashboard', clientlog_fields(['page' => 'Dashboard'], '')['page']);
    assert_eq('myforms', clientlog_fields(['page' => 'my/../forms!'], '')['page']);
    assert_eq('unknown', clientlog_fields(['page' => '///'], '')['page']);
    assert_eq('unknown', clientlog_fields([], '')['page']);
});

t('message and stack are clamped, ua too', function (): void {
    $fields = clientlog_fields([
        'message' => str_repeat('m', 600),
        'stack' => str_repeat('s', 5000),
    ], str_repeat('u', 400));
    assert_eq(500, mb_strlen($fields['message']));
    assert_eq(2000, mb_strlen($fields['stack']));
    assert_eq(300, mb_strlen($fields['ua']));
});
