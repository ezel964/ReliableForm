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
        'SELECT f.id, f.user_id, f.public_id, f.title, f.description, f.fields, f.webhook_url, f.is_published,
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
