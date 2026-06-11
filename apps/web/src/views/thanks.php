<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $form
 * @var string $publicId
 * @var string $thankyouMessage custom copy from the settings page ('' ⇒ default)
 * @var bool $embed keep the "submit another" link inside the chrome-less variant
 */
$thankyouMessage = $thankyouMessage ?? '';
$embed = $embed ?? false;
?>
<div class="card center-card">
  <div class="big-check" aria-hidden="true">✓</div>
  <h1>Thank you!</h1>
  <?php if ($thankyouMessage !== ''): ?>
    <p class="muted"><?= nl2br(e($thankyouMessage)) ?></p>
  <?php else: ?>
    <p class="muted">
      Your response to &ldquo;<?= e((string) $form['title']) ?>&rdquo; was recorded.
    </p>
  <?php endif; ?>
  <p><a class="btn btn-ghost" href="/f/<?= e($publicId) ?><?= $embed ? '/embed' : '' ?>">Submit another response</a></p>
</div>
