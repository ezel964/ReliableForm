<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';

// All fixture keys are UNITTEST_-prefixed so the real .env (already loaded by
// bootstrap) can never collide with them.
$fixture = tempnam(sys_get_temp_dir(), 'rf-env-');
file_put_contents($fixture, implode("\n", [
    '# full-line comment',
    '',
    'UNITTEST_PLAIN=hello',
    '  UNITTEST_TRIM  =  spaced  ',
    'UNITTEST_DQ="double quoted"',
    "UNITTEST_SQ='single quoted'",
    'UNITTEST_EQ=a=b=c',
    'UNITTEST_EMPTY=',
    'UNITTEST_HALFQ="unbalanced',
    'no_equals_sign_line',
    'UNITTEST_PRECEDENCE=fromfile',
]) . "\n");
Config::load($fixture);

t('plain KEY=VALUE is parsed', function (): void {
    assert_eq('hello', Config::get('UNITTEST_PLAIN'));
});

t('keys and values are trimmed', function (): void {
    assert_eq('spaced', Config::get('UNITTEST_TRIM'));
});

t('comment lines and non KEY=VALUE lines are ignored', function (): void {
    assert_eq(null, Config::get('# full-line comment'));
    assert_eq(null, Config::get('no_equals_sign_line'));
});

t('double quotes are stripped', function (): void {
    assert_eq('double quoted', Config::get('UNITTEST_DQ'));
});

t('single quotes are stripped', function (): void {
    assert_eq('single quoted', Config::get('UNITTEST_SQ'));
});

t('unbalanced quote is kept literally', function (): void {
    assert_eq('"unbalanced', Config::get('UNITTEST_HALFQ'));
});

t('value may itself contain = (split on first only)', function (): void {
    assert_eq('a=b=c', Config::get('UNITTEST_EQ'));
});

t('empty value falls back: get() treats it as set-but-empty', function (): void {
    // Config::load stores '' — get() returns it (only real env '' is skipped).
    assert_eq('', Config::get('UNITTEST_EMPTY', 'default'));
});

t('real environment variable wins over the file value', function (): void {
    putenv('UNITTEST_PRECEDENCE=fromenv');
    assert_eq('fromenv', Config::get('UNITTEST_PRECEDENCE'));
    putenv('UNITTEST_PRECEDENCE'); // unset → file value returns
    assert_eq('fromfile', Config::get('UNITTEST_PRECEDENCE'));
});

t('empty real env var does NOT shadow the file value', function (): void {
    putenv('UNITTEST_PRECEDENCE=');
    assert_eq('fromfile', Config::get('UNITTEST_PRECEDENCE'));
    putenv('UNITTEST_PRECEDENCE');
});

t('missing file is a no-op (no throw, defaults still apply)', function (): void {
    Config::load('/nonexistent/definitely-not-here.env');
    assert_eq('fallback', Config::get('UNITTEST_NEVER_SET', 'fallback'));
});

t('absent key returns the default (null when none given)', function (): void {
    assert_eq(null, Config::get('UNITTEST_NEVER_SET'));
    assert_eq('d', Config::get('UNITTEST_NEVER_SET', 'd'));
});

unlink($fixture);
