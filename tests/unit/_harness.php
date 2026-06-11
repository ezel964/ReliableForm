<?php

declare(strict_types=1);

// Tests run against the real kernel: RF_ROOT, .env, autoloader, helpers.
require_once __DIR__ . '/../../lib/bootstrap.php';

final class SkipTest extends Exception
{
}

$GLOBALS['__t'] = ['pass' => 0, 'fail' => 0, 'skip' => 0];

function t(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['__t']['pass']++;
        echo "  ✓ {$name}\n";
    } catch (SkipTest $e) {
        $GLOBALS['__t']['skip']++;
        echo "  ~ {$name} (skip: {$e->getMessage()})\n";
    } catch (Throwable $e) {
        $GLOBALS['__t']['fail']++;
        echo "  ✗ {$name}\n      {$e->getMessage()} ({$e->getFile()}:{$e->getLine()})\n";
    }
}

function skip(string $reason): never
{
    throw new SkipTest($reason);
}

function assert_true(bool $cond, string $msg = 'expected true, got false'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg !== '' ? $msg . ' — ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_match(string $pattern, string $subject, string $msg = ''): void
{
    if (preg_match($pattern, $subject) !== 1) {
        throw new RuntimeException(($msg !== '' ? $msg . ' — ' : '')
            . $pattern . ' does not match: ' . substr($subject, 0, 160));
    }
}

register_shutdown_function(static function (): void {
    $t = $GLOBALS['__t'];
    printf("  %d passed, %d failed, %d skipped\n", $t['pass'], $t['fail'], $t['skip']);
    exit($t['fail'] > 0 ? 1 : 0);
});
