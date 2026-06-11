<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $form
 * @var string $publicId
 */
?>
<div class="card center-card">
  <div class="big-check" aria-hidden="true">✓</div>
  <h1>Thank you!</h1>
  <p class="muted">
    Your response to &ldquo;<?= e((string) $form['title']) ?>&rdquo; was recorded.
  </p>
  <p><a class="btn btn-ghost" href="/f/<?= e($publicId) ?>">Submit another response</a></p>
</div>
