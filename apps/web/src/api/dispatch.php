<?php

declare(strict_types=1);

/**
 * REST API v1 dispatcher. Included by the front controller for every ^/v1/
 * request (RF_API already defined, CSRF guard never reached). Runs in
 * index.php scope: $method, $path, $params and $routePattern (captured by
 * reference in the instrumentation shutdown hook) are live here.
 *
 * Every branch ends in api_json()/api_error(), which exit — control never
 * returns to the web router, and no session is ever started.
 */

require __DIR__ . '/support.php';

// ---- route matching (path first, then method => 404 vs 405) ---------------
$apiHandlers = null; // method => [route pattern, endpoint file]

if ($path === '/v1/me') {
    $apiHandlers = ['GET' => ['GET /v1/me', 'me']];
} elseif ($path === '/v1/rum') {
    $apiHandlers = ['POST' => ['POST /v1/rum', 'rum']];
} elseif ($path === '/v1/clientlog') {
    $apiHandlers = ['POST' => ['POST /v1/clientlog', 'clientlog']];
} elseif ($path === '/v1/forms') {
    $apiHandlers = [
        'GET' => ['GET /v1/forms', 'forms_index'],
        'POST' => ['POST /v1/forms', 'form_create'],
    ];
} elseif (preg_match('#^/v1/forms/([a-z0-9]{10})$#', $path, $m) === 1) {
    $params['public_id'] = $m[1];
    $apiHandlers = [
        'GET' => ['GET /v1/forms/{public_id}', 'form_show'],
        'PUT' => ['PUT /v1/forms/{public_id}', 'form_update'],
        'DELETE' => ['DELETE /v1/forms/{public_id}', 'form_delete'],
    ];
} elseif (preg_match('#^/v1/forms/([a-z0-9]{10})/settings$#', $path, $m) === 1) {
    $params['public_id'] = $m[1];
    $apiHandlers = [
        'GET' => ['GET /v1/forms/{public_id}/settings', 'form_settings_show'],
        'PUT' => ['PUT /v1/forms/{public_id}/settings', 'form_settings_update'],
    ];
} elseif (preg_match('#^/v1/forms/([a-z0-9]{10})/submissions$#', $path, $m) === 1) {
    $params['public_id'] = $m[1];
    $apiHandlers = [
        'GET' => ['GET /v1/forms/{public_id}/submissions', 'submissions_index'],
        'POST' => ['POST /v1/forms/{public_id}/submissions', 'submission_create'],
    ];
} elseif (preg_match('#^/v1/submissions/(\d+)$#', $path, $m) === 1) {
    $params['id'] = $m[1];
    $apiHandlers = ['GET' => ['GET /v1/submissions/{id}', 'submission_show']];
}

if ($apiHandlers === null) {
    $routePattern = '<v1 404>';
    api_error('not_found', 'No such API endpoint.', 404);
}

if (!isset($apiHandlers[$method])) {
    $routePattern = '<v1 405>';
    api_error(
        'method_not_allowed',
        'Method not allowed. Allowed: ' . implode(', ', array_keys($apiHandlers)) . '.',
        405,
        [],
        ['Allow: ' . implode(', ', array_keys($apiHandlers))]
    );
}

[$routePattern, $apiEndpoint] = $apiHandlers[$method];

// ---- auth (+ per-key rate limit), then the endpoint ------------------------
// Telemetry beacons (rum, clientlog) are sessionless and unauthenticated by
// design — the browser cannot present an API key, and they only sink metrics.
$beaconEndpoints = ['rum', 'clientlog'];
if (!in_array($apiEndpoint, $beaconEndpoints, true)) {
    $apiUser = api_authenticate();
}

require __DIR__ . '/' . $apiEndpoint . '.php';
