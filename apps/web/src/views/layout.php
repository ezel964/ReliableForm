<?php

declare(strict_types=1);

/**
 * Shared layout.
 * @var string $title
 * @var string $content
 * @var array<string,mixed>|null $currentUser
 * @var list<string> $scripts
 * @var int|null $refresh
 * @var bool $embed chrome-less variant for /f/{public_id}/embed (no nav/footer)
 */
$scripts = $scripts ?? [];
$refresh = $refresh ?? null;
$embed = $embed ?? false;
// Current path (query string stripped) for aria-current on the nav.
$navPath = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
// Frontend SRE: a stable page token + the telemetry bundle (if built). Page
// handlers may override by setting $rfPage before render.
$rfPage = $rfPage ?? (static function (string $p): string {
    $p = trim($p, '/');
    if ($p === '') {
        return 'home';
    }
    if (preg_match('#^f/[a-z0-9]{10}#', $p) === 1) {
        return 'public_form';
    }
    return preg_replace('/[^a-z0-9_]/', '_', strtolower(explode('/', $p)[0])) ?: 'web';
})($navPath);
$rfManifest = is_file(RF_ROOT . '/frontend/build/asset-manifest.json')
    ? (json_decode((string) file_get_contents(RF_ROOT . '/frontend/build/asset-manifest.json'), true) ?: [])
    : [];
$rfTelemetry = $rfManifest['telemetry.js'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title><?= e($title) ?> · ReliableForm</title>
<?php if ($refresh !== null): ?>
<meta http-equiv="refresh" content="<?= (int) $refresh ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body<?= $embed ? ' class="embed-page"' : '' ?> data-rf-page="<?= e($rfPage) ?>">
<?php if (!$embed): ?>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
  <div class="container header-bar">
    <a class="brand" href="/">
      <svg class="brand-mark" width="26" height="26" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
        <rect x="1.5" y="1.5" width="29" height="29" rx="8" fill="#f97316"/>
        <path d="M9.5 10.5h13M9.5 16h7.5" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
        <path d="M9.5 23l3 3 6.5-6.5" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
      </svg>
      Reliable<span>Form</span>
    </a>
    <nav class="site-nav" aria-label="Main">
      <?php if ($currentUser !== null): ?>
        <a class="nav-link" href="/dashboard"<?= $navPath === '/dashboard' ? ' aria-current="page"' : '' ?>>Dashboard</a>
        <a class="btn btn-primary btn-small" href="/forms/new"<?= $navPath === '/forms/new' ? ' aria-current="page"' : '' ?>>+ New form</a>
        <span class="nav-sep" aria-hidden="true"></span>
        <span class="nav-user" title="<?= e((string) $currentUser['name']) ?>"><?= e((string) $currentUser['name']) ?></span>
        <form method="post" action="/logout" class="inline-form">
          <?= Csrf::field() ?>
          <button type="submit" class="link-button">Log out</button>
        </form>
      <?php else: ?>
        <a class="nav-link" href="/login"<?= $navPath === '/login' ? ' aria-current="page"' : '' ?>>Log in</a>
        <a class="btn btn-primary btn-small" href="/register">Sign up free</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<?php endif; ?>
<main class="container main" id="main">
  <?php foreach (safe_flashes() as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<?php if (!$embed): ?>
<footer class="site-footer">
  <div class="container">
    <span class="footer-instance">served by <code><?= e(instance_id()) ?></code></span>
    <span><a href="/status">system status</a></span>
  </div>
</footer>
<?php endif; ?>
<script src="/assets/js/app.js" defer></script>
<?php foreach ($scripts as $src): ?>
<script src="<?= e($src) ?>" defer></script>
<?php endforeach; ?>
<?php if ($rfTelemetry !== null): ?>
<script>window.__rfrouter = window.__rfrouter || {"TRACE_ID":<?= json_encode(Trace::id(), JSON_UNESCAPED_SLASHES) ?>,"ASSET_PATH":"/build/","ACTIVE_PAGE":<?= json_encode($rfPage, JSON_UNESCAPED_SLASHES) ?>};</script>
<script src="<?= e($rfTelemetry) ?>" defer></script>
<?php endif; ?>
</body>
</html>
