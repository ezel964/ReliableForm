<?php

declare(strict_types=1);

/**
 * Shared layout.
 * @var string $title
 * @var string $content
 * @var array<string,mixed>|null $currentUser
 * @var list<string> $scripts
 * @var int|null $refresh
 */
$scripts = $scripts ?? [];
$refresh = $refresh ?? null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · ReliableForm</title>
<?php if ($refresh !== null): ?>
<meta http-equiv="refresh" content="<?= (int) $refresh ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<header class="site-header">
  <div class="container header-bar">
    <a class="brand" href="/">Reliable<span>Form</span></a>
    <nav class="site-nav">
      <?php if ($currentUser !== null): ?>
        <span class="nav-user">Hi, <?= e((string) $currentUser['name']) ?></span>
        <a href="/dashboard">Dashboard</a>
        <a class="btn btn-primary btn-small" href="/forms/new">New form</a>
        <form method="post" action="/logout" class="inline-form">
          <?= Csrf::field() ?>
          <button type="submit" class="link-button">Log out</button>
        </form>
      <?php else: ?>
        <a href="/login">Log in</a>
        <a class="btn btn-primary btn-small" href="/register">Sign up free</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="container main">
  <?php foreach (safe_flashes() as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<footer class="site-footer">
  <div class="container">
    served by <code><?= e(instance_id()) ?></code> · <a href="/status">system status</a>
  </div>
</footer>
<script src="/assets/js/app.js" defer></script>
<?php foreach ($scripts as $src): ?>
<script src="<?= e($src) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
