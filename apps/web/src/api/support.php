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

/**
 * Require an application/json request body, else 415. Returns the decoded body
 * as an array (422 when it is not a JSON object). Mirrors submission_create.php.
 *
 * @return array<string, mixed>
 */
function api_json_body(): array
{
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
    if ($mediaType !== 'application/json') {
        api_error('unsupported_media_type', 'Content-Type must be application/json.', 415);
    }
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        api_error('validation_failed', 'Body must be a valid JSON object.', 422);
    }
    return $body;
}

/**
 * Adapt a JSON builder body ({title, description, webhook_url, fields:[...]})
 * into the $_POST shape validate_builder_request() expects (fields as a JSON
 * string). Pure — no I/O. Non-string scalars are left for the validator to
 * reject, exactly as a raw form post would.
 *
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function api_builder_post_from_body(array $body): array
{
    return [
        'title' => $body['title'] ?? '',
        'description' => $body['description'] ?? '',
        'webhook_url' => $body['webhook_url'] ?? '',
        'fields_json' => array_key_exists('fields', $body)
            ? (string) json_encode($body['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '',
    ];
}

/**
 * Adapt a JSON settings body into the $_POST shape validate_settings_request()
 * expects (submission_limit as a string; checkboxes as truthy). Pure.
 *
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function api_settings_post_from_body(array $body): array
{
    $limit = $body['submission_limit'] ?? '';
    if (is_int($limit)) {
        $limit = (string) $limit;
    }
    $message = $body['thankyou_message'] ?? '';
    return [
        'is_published' => !empty($body['is_published']) ? '1' : '',
        'submission_limit' => is_string($limit) ? $limit : '',
        'thankyou_message' => is_string($message) ? $message : '',
        'autoresponder_enabled' => !empty($body['autoresponder_enabled']) ? '1' : '',
    ];
}

/**
 * Shape a forms row for JSON output (the create/update endpoints reuse this so
 * their response matches GET /v1/forms/{public_id}, incl. conditions).
 *
 * @param array<string, mixed> $form
 * @return array<string, mixed>
 */
function api_form_payload(array $form): array
{
    return [
        'id' => (int) $form['id'],
        'public_id' => (string) $form['public_id'],
        'title' => (string) $form['title'],
        'description' => $form['description'] !== null ? (string) $form['description'] : null,
        'fields' => api_decode_fields((string) $form['fields']),
        'conditions' => api_decode_fields((string) ($form['conditions'] ?? '')),
        'is_published' => (int) $form['is_published'] === 1,
        'webhook_url' => $form['webhook_url'] !== null ? (string) $form['webhook_url'] : null,
        'created_at' => (string) $form['created_at'],
        'updated_at' => (string) $form['updated_at'],
    ];
}

/**
 * Frontend SRE — pure helpers (no I/O, unit-testable).
 * The browser ships Core Web Vitals to POST /v1/rum and JS errors to
 * POST /v1/clientlog; both are sessionless beacons that sink into the existing
 * StatsD / AppLog pipeline.
 */

/**
 * Map a web-vitals beacon to a single StatsD timing, or null when the payload
 * is not a metric we accept. CLS is unitless so we scale ×1000 for an
 * integer-friendly StatsD value; the others are already milliseconds.
 *
 * @param array<string, mixed> $payload
 * @return array{stat: string, value: float}|null
 */
function rum_stat_for(array $payload): ?array
{
    $allowed = ['LCP' => 'lcp', 'INP' => 'inp', 'CLS' => 'cls', 'TTFB' => 'ttfb', 'FCP' => 'fcp', 'FID' => 'fid'];
    $metric = is_string($payload['metric'] ?? null) ? $payload['metric'] : '';
    if (!isset($allowed[$metric]) || !is_numeric($payload['value'] ?? null)) {
        return null;
    }
    $value = (float) $payload['value'];
    $statValue = $metric === 'CLS' ? round($value * 1000) : $value;
    return ['stat' => 'web.client.' . $allowed[$metric], 'value' => $statValue];
}

/**
 * Normalise a JS error/rejection beacon into AppLog fields. Page is reduced to
 * a safe token; strings are clamped (AppLog clamps again at 512).
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function clientlog_fields(array $payload, string $ua): array
{
    $kind = in_array($payload['kind'] ?? '', ['error', 'unhandledrejection'], true) ? $payload['kind'] : 'error';
    $page = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($payload['page'] ?? 'unknown'))) ?: 'unknown';
    return [
        'kind' => $kind,
        'page' => $page,
        'message' => mb_substr((string) ($payload['message'] ?? ''), 0, 500),
        'stack' => mb_substr((string) ($payload['stack'] ?? ''), 0, 2000),
        'url' => mb_substr((string) ($payload['url'] ?? ''), 0, 500),
        'line' => (int) ($payload['line'] ?? 0),
        'col' => (int) ($payload['col'] ?? 0),
        'ua' => mb_substr($ua, 0, 300),
    ];
}

/**
 * Per-IP fixed-window limit for the clientlog beacon, mirroring api_rate_limit:
 * ratelimit:clientlog:<ip>, INCR + EXPIRE 60. Fails OPEN (returns true) on any
 * Redis error — observability must never reject a request because Redis blinked.
 */
function clientlog_rate_ok(string $ip): bool
{
    $limit = (int) Config::get('CLIENTLOG_RATE_PER_MIN', '120');
    if ($limit <= 0) {
        return true;
    }
    try {
        $redis = RedisClient::instance();
        $rateKey = 'ratelimit:clientlog:' . $ip;
        $count = $redis->incr($rateKey);
        if ($count === 1) {
            $redis->expire($rateKey, 60);
        }
        return $count <= $limit;
    } catch (RedisException) {
        return true;
    }
}
