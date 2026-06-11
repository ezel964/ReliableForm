<?php

declare(strict_types=1);

define('RF_ROOT', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    $file = RF_ROOT . '/lib/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once RF_ROOT . '/lib/helpers.php';

Config::load(RF_ROOT . '/.env');

date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', RF_ROOT . '/storage/logs/php-error.log');

set_exception_handler(function (Throwable $e): void {
    error_log(sprintf(
        '[%s] Uncaught %s: %s in %s:%d',
        instance_id(),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    if (PHP_SAPI === 'cli') {
        Metrics::increment('worker.error');
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    // API requests (the /v1 dispatcher defines RF_API) get JSON, never HTML.
    Metrics::increment(defined('RF_API') ? 'api.error' : 'web.error');
    if (defined('RF_API')) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok' => false,
            'error' => [
                'code' => 'internal_error',
                'message' => Config::get('APP_ENV', 'dev') === 'dev'
                    ? $e->getMessage()
                    : 'Internal error. Try again shortly.',
                'trace_id' => Trace::id(),
            ],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $detail = Config::get('APP_ENV', 'dev') === 'dev'
        ? e(get_class($e) . ': ' . $e->getMessage())
        : 'Please try again in a moment.';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Something went wrong — ReliableForm</title>'
        . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
        . 'background:#f6f7fb;color:#1f2937;display:flex;align-items:center;justify-content:center;'
        . 'min-height:100vh;margin:0}main{background:#fff;border-radius:12px;padding:40px 48px;'
        . 'box-shadow:0 1px 4px rgba(15,23,42,.08);max-width:520px;text-align:center}'
        . 'h1{font-size:22px;margin:0 0 8px}p{color:#6b7280;line-height:1.6}'
        . 'code{background:#f3f4f6;border-radius:6px;padding:2px 6px;font-size:13px}'
        . 'a{color:#f97316;font-weight:600;text-decoration:none}</style></head><body><main>'
        . '<h1>Something went wrong</h1>'
        . '<p>' . $detail . '</p>'
        . '<p><a href="/">Back to ReliableForm</a> · served by <code>' . e(instance_id()) . '</code></p>'
        . '</main></body></html>';
    exit;
});
