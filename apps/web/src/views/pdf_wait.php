<?php

declare(strict_types=1);

/**
 * Friendly PDF status page: auto-refreshing while pending/processing
 * (the layout emits the meta refresh), an error note when failed/missing.
 *
 * @var bool $failed
 * @var string|null $error
 * @var int $submissionId
 */
$statusLabel = $statusLabel ?? 'pending';
?>
<div class="card center-card">
  <?php if ($failed): ?>
    <div class="big-info" aria-hidden="true">✕</div>
    <h1>PDF unavailable</h1>
    <p class="muted">We could not produce the PDF for submission #<?= (int) $submissionId ?>.</p>
    <?php if (!empty($error)): ?>
      <p class="error-note"><?= e((string) $error) ?></p>
    <?php endif; ?>
  <?php else: ?>
    <div class="spinner" aria-hidden="true"></div>
    <h1>Your PDF is still rendering</h1>
    <p class="muted">
      Current status: <?= status_badge($statusLabel) ?> — a background worker is on it.
      This page refreshes automatically every 3&nbsp;seconds.
    </p>
  <?php endif; ?>
  <p><a class="btn btn-secondary" href="/submissions/<?= (int) $submissionId ?>">&larr; Back to submission</a></p>
</div>
