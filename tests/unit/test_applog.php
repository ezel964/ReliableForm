<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';

const APPLOG_FILE = RF_ROOT . '/storage/logs/app-unittest.jsonl';

@unlink(APPLOG_FILE); // never inherit state from a previous run

t('APPLOG_ENABLED=0 writes nothing', function (): void {
    putenv('APPLOG_ENABLED=0');
    assert_true(!AppLog::enabled());
    AppLog::event('unit_disabled', ['k' => 'v']);
    assert_true(!is_file(APPLOG_FILE), 'no app-unittest.jsonl may appear when disabled');
});

t('enabled ⇒ one valid single-line JSON event with ts/instance/event/trace_id', function (): void {
    putenv('APPLOG_ENABLED=1');
    Trace::start();
    AppLog::event('unit_event', ['answer' => 42, 'note' => 'plain']);
    assert_true(is_file(APPLOG_FILE));
    $lines = file(APPLOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    assert_eq(1, count($lines), 'exactly one line per event');
    $doc = json_decode($lines[0], true);
    assert_true(is_array($doc), 'line is valid JSON');
    assert_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $doc['ts']);
    assert_eq('unittest', $doc['instance']);
    assert_eq('unit_event', $doc['event']);
    assert_match('/^[0-9a-f]{32}$/', $doc['trace_id']);
    assert_eq(Trace::id(), $doc['trace_id']);
    assert_eq(42, $doc['answer']);
    assert_eq('plain', $doc['note']);
});

t('string fields over 512 chars are truncated with an ellipsis', function (): void {
    putenv('APPLOG_ENABLED=1');
    @unlink(APPLOG_FILE);
    AppLog::event('unit_trunc', ['long' => str_repeat('x', 600), 'short' => str_repeat('y', 512)]);
    $doc = json_decode(trim((string) file_get_contents(APPLOG_FILE)), true);
    assert_eq(str_repeat('x', 512) . '…', $doc['long']);
    assert_eq(str_repeat('y', 512), $doc['short'], 'exactly 512 chars is left alone');
});

t('events append; each line stays independently parseable', function (): void {
    putenv('APPLOG_ENABLED=1');
    @unlink(APPLOG_FILE);
    AppLog::event('first');
    AppLog::event('second', ['n' => 2]);
    $lines = file(APPLOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    assert_eq(2, count($lines));
    assert_eq('first', json_decode($lines[0], true)['event']);
    assert_eq('second', json_decode($lines[1], true)['event']);
});

putenv('APPLOG_ENABLED'); // restore .env-driven behavior
@unlink(APPLOG_FILE);     // leave storage/logs as found
