<?php

declare(strict_types=1);

require __DIR__ . '/../../../lib/bootstrap.php';

// Adopt the caller's W3C trace context (or start a fresh trace) before any work.
Trace::start($_SERVER['HTTP_TRACEPARENT'] ?? null);

// `php -S` router script behavior: let the built-in server serve real files
// (assets) directly. Nginx serves /assets/ itself in the full stack.
if (PHP_SAPI === 'cli-server') {
    $file = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_string($file) && $file !== '/' && !str_contains($file, '..') && is_file(__DIR__ . $file)) {
        return false;
    }
}

require RF_ROOT . '/apps/web/src/support.php';
require RF_ROOT . '/apps/web/src/validation.php';
require RF_ROOT . '/apps/web/src/conditions.php';

header('X-Served-By: ' . instance_id());
header('X-Trace-Id: ' . Trace::id());

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'HEAD') {
    $method = 'GET'; // the SAPI drops the body itself
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? $path : '/';
if ($path !== '/') {
    $path = rtrim($path, '/');
    if ($path === '') {
        $path = '/';
    }
}

// REST API v1 is its own metric family (api.* INSTEAD of web.* — never both)
// and its own error mode (bootstrap's handler emits the JSON envelope when
// RF_API is defined). Decided before any counter fires so nothing double-counts.
if (preg_match('#^/v1(/|$)#', $path) === 1) {
    define('RF_API', true);
    Metrics::increment('api.request');
} else {
    Metrics::increment('web.request');
}

$requestStart = microtime(true);
$routePattern = '<404>'; // overwritten by the dispatcher below when a route matches
register_shutdown_function(static function () use ($requestStart, &$routePattern): void {
    $requestEnd = microtime(true);
    $status = http_response_code();
    $status = is_int($status) ? $status : 200;

    // v1 traffic reports as api.request_ms / api.status.<code>; everything
    // else keeps the web.* family. One family per request, never both.
    $family = defined('RF_API') ? 'api' : 'web';
    Metrics::timing($family . '.request_ms', ($requestEnd - $requestStart) * 1000.0);
    Metrics::increment($family . '.status.' . $status);

    $httpMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $requestId = (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');

    $attrs = [
        'http.request.method' => $httpMethod,
        'http.route' => $routePattern,
        'http.response.status_code' => $status,
        'app.request_id' => $requestId,
    ];
    $logFields = [
        'method' => $httpMethod,
        'route' => $routePattern,
        'status' => $status,
        'dur_ms' => round(($requestEnd - $requestStart) * 1000.0, 1),
        'request_id' => $requestId,
    ];
    // user_id only when a session is ALREADY active — never start one here.
    // (v1 requests never have one: the API path is sessionless by contract.)
    if (session_status() === PHP_SESSION_ACTIVE) {
        $user = safe_user();
        if ($user !== null) {
            $attrs['app.user_id'] = (int) $user['id'];
            $logFields['user_id'] = (int) $user['id'];
        }
    }

    // v1 route patterns already carry the method ('GET /v1/me'); don't double it.
    $spanName = defined('RF_API') ? $routePattern : $httpMethod . ' ' . $routePattern;
    Otel::span(
        $spanName,
        $requestStart,
        $requestEnd,
        Otel::KIND_SERVER,
        $attrs,
        $status >= 500
    );
    Otel::flush();
    AppLog::event('http_request', $logFields);
});

// /v1 dispatch happens BEFORE the CSRF guard and never touches the session
// (no Session::start(), no Csrf, no Auth::user(), no flash/render — API
// responses must never mint an rfsession cookie). The dispatcher always
// responds and exits; control never falls through to the web router.
if (defined('RF_API')) {
    require RF_ROOT . '/apps/web/src/api/dispatch.php';
}

$page = null;
$params = [];
$m = [];

if ($path === '/' && $method === 'GET') {
    $page = 'home';
    $routePattern = '/';
} elseif ($path === '/register' && in_array($method, ['GET', 'POST'], true)) {
    $page = 'register';
    $routePattern = '/register';
} elseif ($path === '/login' && in_array($method, ['GET', 'POST'], true)) {
    $page = 'login';
    $routePattern = '/login';
} elseif ($path === '/logout' && $method === 'POST') {
    $page = 'logout';
    $routePattern = '/logout';
} elseif ($path === '/dashboard' && $method === 'GET') {
    $page = 'dashboard';
    $routePattern = '/dashboard';
} elseif ($path === '/forms/new' && $method === 'GET') {
    $page = 'form_new';
    $routePattern = '/forms/new';
} elseif ($path === '/forms' && $method === 'POST') {
    $page = 'form_create';
    $routePattern = '/forms';
} elseif ($method === 'GET' && preg_match('#^/forms/(\d+)/edit$#', $path, $m) === 1) {
    $page = 'form_edit';
    $params['id'] = $m[1];
    $routePattern = '/forms/{id}/edit';
} elseif ($method === 'POST' && preg_match('#^/forms/(\d+)/delete$#', $path, $m) === 1) {
    $page = 'form_delete';
    $params['id'] = $m[1];
    $routePattern = '/forms/{id}/delete';
} elseif ($method === 'GET' && preg_match('#^/forms/(\d+)/submissions$#', $path, $m) === 1) {
    $page = 'form_submissions';
    $params['id'] = $m[1];
    $routePattern = '/forms/{id}/submissions';
} elseif (in_array($method, ['GET', 'POST'], true) && preg_match('#^/forms/(\d+)/settings$#', $path, $m) === 1) {
    $page = 'form_settings';
    $params['id'] = $m[1];
    $routePattern = '/forms/{id}/settings';
} elseif ($method === 'GET' && preg_match('#^/forms/(\d+)/export\.csv$#', $path, $m) === 1) {
    $page = 'export_csv';
    $params['id'] = $m[1];
    $routePattern = '/forms/{id}/export.csv';
} elseif ($path === '/account/api-key' && $method === 'POST') {
    $page = 'account_api_key';
    $routePattern = '/account/api-key';
} elseif ($method === 'POST' && preg_match('#^/forms/(\d+)$#', $path, $m) === 1) {
    $page = 'form_update';
    $params['id'] = $m[1];
    $routePattern = '/forms/{id}';
} elseif ($method === 'GET' && preg_match('#^/submissions/(\d+)/pdf$#', $path, $m) === 1) {
    $page = 'submission_pdf';
    $params['id'] = $m[1];
    $routePattern = '/submissions/{id}/pdf';
} elseif ($method === 'GET' && preg_match('#^/submissions/(\d+)$#', $path, $m) === 1) {
    $page = 'submission_show';
    $params['id'] = $m[1];
    $routePattern = '/submissions/{id}';
} elseif (preg_match('#^/f/([a-z0-9]{10})$#', $path, $m) === 1 && in_array($method, ['GET', 'POST'], true)) {
    $page = $method === 'POST' ? 'public_submit' : 'public_form';
    $params['public_id'] = $m[1];
    $routePattern = '/f/{public_id}';
} elseif ($method === 'GET' && preg_match('#^/f/([a-z0-9]{10})/thanks$#', $path, $m) === 1) {
    $page = 'thanks';
    $params['public_id'] = $m[1];
    $routePattern = '/f/{public_id}/thanks';
} elseif ($method === 'GET' && preg_match('#^/f/([a-z0-9]{10})/embed$#', $path, $m) === 1) {
    $page = 'public_form';
    $params['public_id'] = $m[1];
    $params['embed'] = '1';
    $routePattern = '/f/{public_id}/embed';
} elseif ($path === '/healthz' && $method === 'GET') {
    $page = 'healthz';
    $routePattern = '/healthz';
}

if ($page === null) {
    not_found();
}

if ($method === 'POST') {
    Csrf::validate(); // every POST route is CSRF-checked before any work
}

require RF_ROOT . '/apps/web/src/pages/' . $page . '.php';
