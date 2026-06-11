<?php

declare(strict_types=1);

/**
 * Web-app helpers shared by all page scripts. The kernel (lib/) stays untouched;
 * everything here is thin glue on top of it.
 */

/** Auth::user() that tolerates Redis/MySQL being down — public pages must still render. */
function safe_user(): ?array
{
    try {
        return Auth::user();
    } catch (Throwable) {
        return null;
    }
}

/** flashes() that tolerates the session backend being down. */
function safe_flashes(): array
{
    try {
        return flashes();
    } catch (Throwable) {
        return [];
    }
}

/** $_POST value as a string ('' when absent or not a scalar string). */
function post_str(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? $v : '';
}

/** Render a view inside the shared layout and echo the full page. */
function render_page(string $view, array $vars = [], int $status = 200): void
{
    if (!array_key_exists('currentUser', $vars)) {
        $vars['currentUser'] = safe_user();
    }
    $vars['title'] = $vars['title'] ?? 'ReliableForm';
    http_response_code($status);
    $content = render($view, $vars);
    echo render('layout', [
        'title' => $vars['title'],
        'currentUser' => $vars['currentUser'],
        'content' => $content,
        'scripts' => $vars['scripts'] ?? [],
        'refresh' => $vars['refresh'] ?? null,
        'embed' => !empty($vars['embed']),
    ]);
}

/** Friendly 404 page. */
function not_found(string $message = "We couldn't find that page."): never
{
    render_page('error_404', ['title' => 'Page not found', 'message' => $message], 404);
    exit;
}

/** Fetch a form by id and verify it belongs to $user; 404s otherwise (never leaks existence). */
function owned_form(array $user, int $formId): array
{
    $form = DB::one('SELECT * FROM forms WHERE id = ?', [$formId]);
    if ($form === null || (int) $form['user_id'] !== (int) $user['id']) {
        not_found('That form does not exist.');
    }
    return $form;
}

/**
 * Public form row, read through cache:form:<public_id> (TTL 60s).
 * Falls back to the DB on any Redis problem. Includes owner_email for the
 * emails-row insert on submission.
 */
function load_public_form(string $publicId): ?array
{
    $key = 'cache:form:' . $publicId;
    try {
        $raw = RedisClient::instance()->get($key);
        if ($raw !== null) {
            $row = json_decode($raw, true);
            if (is_array($row)) {
                return $row;
            }
        }
    } catch (Throwable) {
        // cache unavailable — fall through to the DB
    }

    $row = DB::one(
        'SELECT f.id, f.user_id, f.public_id, f.title, f.description, f.fields, f.conditions, f.webhook_url, f.is_published,
                f.submission_limit, f.thankyou_message, f.autoresponder_enabled,
                u.email AS owner_email
           FROM forms f
           JOIN users u ON u.id = f.user_id
          WHERE f.public_id = ?',
        [$publicId]
    );
    if ($row === null) {
        return null;
    }

    try {
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            RedisClient::instance()->setex($key, 60, $json);
        }
    } catch (Throwable) {
        // cache write is best-effort
    }
    return $row;
}

/** DEL cache:form:<public_id>; logs instead of failing when Redis is down. */
function invalidate_form_cache(string $publicId): void
{
    try {
        RedisClient::instance()->del('cache:form:' . $publicId);
    } catch (Throwable $e) {
        error_log(sprintf(
            '[%s] cache invalidation failed for form %s: %s (cache expires in <=60s anyway)',
            instance_id(),
            $publicId,
            $e->getMessage()
        ));
    }
}

/**
 * Why a public form refuses input: 'unpublished' | 'limit' | null (open).
 * Cached rows may predate the settings columns (≤60s window) — missing keys
 * default to "open". The COUNT is advisory under concurrent submits; a
 * couple of extras past the limit is acceptable for this playground.
 *
 * @param array<string, mixed> $form
 */
function form_closed_reason(array $form): ?string
{
    if ((int) ($form['is_published'] ?? 1) !== 1) {
        return 'unpublished';
    }
    $limit = $form['submission_limit'] ?? null;
    if ($limit !== null && (int) $limit > 0) {
        $row = DB::one('SELECT COUNT(*) AS c FROM submissions WHERE form_id = ?', [(int) $form['id']]);
        if ((int) ($row['c'] ?? 0) >= (int) $limit) {
            return 'limit';
        }
    }
    return null;
}

/** Best-effort view counter: INCR stats:views:<public_id> (no TTL) — Redis trouble never breaks the page. */
function record_form_view(string $publicId): void
{
    try {
        RedisClient::instance()->incr('stats:views:' . $publicId);
    } catch (Throwable) {
        // counters just freeze during a Redis outage
    }
    Metrics::increment('web.form.view');
}

/**
 * View counts for a set of forms (one MGET round trip), keyed by public_id.
 * Returns null when Redis is unreachable — callers render '—' then.
 *
 * @param list<string> $publicIds
 * @return array<string, int>|null
 */
function form_view_counts(array $publicIds): ?array
{
    if ($publicIds === []) {
        return [];
    }
    try {
        $keys = array_map(static fn (string $pid): string => 'stats:views:' . $pid, $publicIds);
        $values = RedisClient::instance()->command('MGET', ...$keys);
        if (!is_array($values)) {
            return null;
        }
        $counts = [];
        foreach (array_values($publicIds) as $i => $pid) {
            $counts[$pid] = isset($values[$i]) && is_string($values[$i]) ? (int) $values[$i] : 0;
        }
        return $counts;
    } catch (Throwable) {
        return null;
    }
}

/** Conversion as "12.5%" (1dp); '—' when views are 0 or unknown (Redis down). */
function conversion_label(int $submissions, ?int $views): string
{
    if ($views === null || $views <= 0) {
        return '—';
    }
    return round($submissions / $views * 100, 1) . '%';
}

/**
 * Short inbox preview: the first $max non-empty answers in field order,
 * each truncated. Plain text — the view e()'s it like any other cell.
 *
 * @param list<array<string, mixed>> $fields
 * @param array<string, mixed> $data decoded submissions.data
 */
function submission_preview(array $fields, array $data, int $max = 2): string
{
    $parts = [];
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $value = $data[(string) ($field['id'] ?? '')] ?? null;
        if (is_array($value)) { // checkbox submits string[]
            $value = implode(', ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '?', $value));
        }
        if (!is_string($value) || trim($value) === '') {
            continue;
        }
        $value = trim($value);
        if (mb_strlen($value) > 48) {
            $value = mb_substr($value, 0, 48) . '…';
        }
        $parts[] = $value;
        if (count($parts) >= $max) {
            break;
        }
    }
    return implode(' · ', $parts);
}

/** Absolute public URL for a form. */
function public_form_url(string $publicId): string
{
    $base = rtrim((string) Config::get('APP_URL', 'http://localhost:8080'), '/');
    return $base . '/f/' . $publicId;
}

/** Small colored status badge (pending=gray, processing=blue, done/sent=green, failed=red). */
function status_badge(string $status): string
{
    $class = match ($status) {
        'done', 'sent' => 'badge-done',
        'processing' => 'badge-processing',
        'failed' => 'badge-failed',
        default => 'badge-pending',
    };
    return '<span class="badge ' . $class . '">' . e($status) . '</span>';
}
