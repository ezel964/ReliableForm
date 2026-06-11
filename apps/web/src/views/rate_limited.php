<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $form
 * @var string $publicId
 */
?>
<div class="card center-card">
  <h1>Slow down a moment</h1>
  <p class="muted">
    We've received a lot of responses to &ldquo;<?= e((string) $form['title']) ?>&rdquo;
    from your network just now. Your last response was not saved &mdash;
    please wait a minute and try again.
  </p>
  <p><a class="btn btn-ghost" href="/f/<?= e($publicId) ?>">Back to the form</a></p>
</div>
