<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $form
 * @var string $reason 'unpublished'|'limit'
 */
?>
<div class="card center-card">
  <div class="big-info" aria-hidden="true">⏸</div>
  <h1>This form is closed</h1>
  <p class="muted">
    <?php if ($reason === 'limit'): ?>
      &ldquo;<?= e((string) $form['title']) ?>&rdquo; is no longer accepting submissions
      &mdash; it has reached its response limit.
    <?php else: ?>
      &ldquo;<?= e((string) $form['title']) ?>&rdquo; is not accepting responses right now.
      Please check back later.
    <?php endif; ?>
  </p>
</div>
