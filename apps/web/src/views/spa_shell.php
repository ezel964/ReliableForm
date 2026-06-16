<?php
/**
 * React SPA host shell, rendered by FrontendLoader.
 * @var array<string,string> $router
 * @var array<mixed>|null $bootstrap
 * @var list<string> $scripts
 * @var string $title
 * @var string $app
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <script>window.__rfrouter = <?= json_encode($router, JSON_UNESCAPED_SLASHES) ?>;</script>
<?php if ($bootstrap !== null): ?>
  <script type="application/json" id="rf-bootstrap"><?= json_encode($bootstrap, JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
</head>
<body data-rf-page="<?= e($app) ?>">
  <div id="root"></div>
<?php foreach ($scripts as $src): ?>
  <script src="<?= e($src) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
