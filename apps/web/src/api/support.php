<?php

declare(strict_types=1);

/**
 * REST API v1 helpers. The whole /v1 path is SESSIONLESS by contract:
 * nothing in this directory may call Session::start() directly or
 * transitively (no Csrf, no Auth::user(), no flash()/render()) — API
 * responses must never set an rfsession cookie.
 */

/**
 * Emit a JSON response and stop. Mirrors json_response() but supports extra
 * headers (e.g. Retry-After) and keeps unicode readable for API consumers.
 *
 * @param array<string, mixed> $data
 * @param list<string> $headers raw header lines
 */
function api_json(array $data, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $h) {
        header($h);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Error envelope per contract:
 * {"ok":false,"error":{"code","message","trace_id"[,"fields"]}}
 *
 * @param array<string, string> $fields per-field messages (validation_failed only)
 * @param list<string> $headers
 */
function api_error(string $code, string $message, int $status, array $fields = [], array $headers = []): never
{
    $error = [
        'code' => $code,
        'message' => $message,
        'trace_id' => Trace::id(),
    ];
    if ($fields !== []) {
        $error['fields'] = $fields;
    }
    api_json(['ok' => false, 'error' => $error], $status, $headers);
}

/** Extract a well-formed key (40 hex chars) from Authorization: Bearer or X-Api-Key. */
function api_request_key(): ?string
{
    $auth = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($auth !== '' && preg_match('/^Bearer\s+([0-9a-fA-F]{40})$/', $auth, $m) === 1) {
        return strtolower($m[1]);
    }
    $header = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($header !== '' && preg_match('/^[0-9a-fA-F]{40}$/', $header) === 1) {
        return strtolower($header);
    }
    return null;
}

/**
 * Per-key rate limit: ratelimit:api:<key>, INCR + EXPIRE 60.
 * Limit API_RATE_LIMIT_PER_MIN (default 120); 0 disables; Redis errors fail
 * open (the limiter must never take the API down with it).
 */
function api_rate_limit(string $key): void
{
    $limit = (int) Config::get('API_RATE_LIMIT_PER_MIN', '120');
    if ($limit <= 0) {
        return;
    }
    try {
        $redis = RedisClient::instance();
        $rateKey = 'ratelimit:api:' . $key;
        $count = $redis->incr($rateKey);
        if ($count === 1) {
            $redis->expire($rateKey, 60);
        }
        if ($count > $limit) {
            Metrics::increment('api.ratelimited');
            api_error(
                'rate_limited',
                'API rate limit exceeded (' . $limit . ' requests per minute per key). Try again shortly.',
                429,
                [],
                ['Retry-After: 30']
            );
        }
    } catch (RedisException) {
        // fail open per contract
    }
}

/**
 * Authenticate the request: missing/malformed key => 401; otherwise rate
 * limit the presented key, then look the owner up by api_key (=> 401 when
 * unknown). The key itself is never echoed back in any response.
 *
 * @return array{id: int, email: string, name: string, created_at: string}
 */
function api_authenticate(): array
{
    $key = api_request_key();
    if ($key === null) {
        api_error(
            'unauthorized',
            'Provide a valid API key via "Authorization: Bearer <key>" or "X-Api-Key: <key>".',
            401
        );
    }
    api_rate_limit($key);
    $user = DB::one('SELECT id, email, name, created_at FROM users WHERE api_key = ?', [$key]);
    if ($user === null) {
        api_error('unauthorized', 'Unknown API key.', 401);
    }
    return [
        'id' => (int) $user['id'],
        'email' => (string) $user['email'],
        'name' => (string) $user['name'],
        'created_at' => (string) $user['created_at'],
    ];
}

/**
 * Form by public_id, owner-scoped: missing or not owned by the authenticated
 * user => identical 404 (never leaks existence).
 *
 * @param array{id: int} $apiUser
 * @return array<string, mixed> the full forms row
 */
function api_owned_form(array $apiUser, string $publicId): array
{
    $form = DB::one('SELECT * FROM forms WHERE public_id = ?', [$publicId]);
    if ($form === null || (int) $form['user_id'] !== $apiUser['id']) {
        api_error('not_found', 'That form does not exist.', 404);
    }
    return $form;
}

/**
 * Decode a JSON column for output as a JSON OBJECT (stdClass keeps an empty
 * map encoding as {} rather than []).
 */
function api_decode_json_object(string $json): object
{
    $decoded = json_decode($json);
    return is_object($decoded) ? $decoded : new stdClass();
}

/** Decode forms.fields for output (always a list). */
function api_decode_fields(string $json): array
{
    $decoded = json_decode($json, true);
    return (is_array($decoded) && array_is_list($decoded)) ? $decoded : [];
}
