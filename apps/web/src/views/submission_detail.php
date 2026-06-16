<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $submission
 * @var list<array<string,mixed>> $fields
 * @var array<string,mixed> $data
 * @var array<string,mixed>|null $pdfJob
 * @var array<string,mixed>|null $emailRow
 * @var array<string,mixed>|null $autoresponderRow
 * @var array<string,mixed>|null $webhookRow null ⇒ form had no webhook at submit time
 */
$webhookRow = $webhookRow ?? null;
$pdfStatus = (string) ($pdfJob['status'] ?? 'pending');
$emailStatus = (string) ($emailRow['status'] ?? 'pending');
$hasFileField = false;
foreach ($fields as $f) {
    if (($f['type'] ?? '') === 'file') {
        $hasFileField = true;
        break;
    }
}
// webhook statuses (pending/delivering/delivered/failed) get their own badge
// classes — status_badge() only knows the pdf/email vocabulary.
$webhookBadgeClass = match ((string) ($webhookRow['status'] ?? 'pending')) {
    'delivered' => 'badge-delivered',
    'delivering' => 'badge-delivering',
    'failed' => 'badge-failed',
    default => 'badge-pending',
};
?>
<div class="page-head">
  <div>
    <h1>Submission <span class="mono">#<?= (int) $submission['id'] ?></span></h1>
    <p class="muted"><?= e((string) $submission['form_title']) ?></p>
  </div>
  <div class="page-head-actions">
    <a class="back-link" href="/forms/<?= (int) $submission['form_id'] ?>/submissions">&larr; All submissions</a>
  </div>
</div>

<div class="detail-cols">
  <div class="card">
    <h2>Answers</h2>
    <dl class="answers">
      <?php foreach ($fields as $field): ?>
        <?php
        $fid = (string) ($field['id'] ?? '');
        $type = (string) ($field['type'] ?? 'text');
        $value = $data[$fid] ?? null;
        ?>
        <div class="answer">
          <dt><?= e((string) ($field['label'] ?? $fid)) ?></dt>
          <dd>
            <?php if ($type === 'checkbox'): ?>
              <?php $values = is_array($value) ? $value : []; ?>
              <?php if ($values === []): ?>
                <span class="no-answer">— no answer —</span>
              <?php else: ?>
                <?= e(implode(', ', array_map('strval', $values))) ?>
              <?php endif; ?>
            <?php elseif ($type === 'rating'): ?>
              <?php $v = is_string($value) ? $value : ''; ?>
              <?php if ($v === ''): ?>
                <span class="no-answer">— no answer —</span>
              <?php else: ?>
                <span class="rating-answer"><?= e($v) ?> / <?= (int) ($field['max'] ?? 5) ?> <span class="star-glyph" aria-hidden="true">★</span></span>
              <?php endif; ?>
            <?php elseif ($type === 'file'): ?>
              <?php /* Uploads are write-only: path + size as TEXT, never a link. */ ?>
              <?php $v = is_string($value) ? $value : ''; ?>
              <?php if ($v === ''): ?>
                <span class="no-answer">— no file —</span>
              <?php else: ?>
                <?php $abs = RF_ROOT . '/' . ltrim($v, '/'); ?>
                <code><?= e($v) ?></code>
                <?php if (is_file($abs)): ?>
                  <span class="muted small">· <?= number_format((float) filesize($abs)) ?> bytes</span>
                <?php else: ?>
                  <span class="muted small">· missing</span>
                <?php endif; ?>
              <?php endif; ?>
            <?php elseif ($type === 'textarea'): ?>
              <?php $v = is_string($value) ? $value : ''; ?>
              <?php if ($v === ''): ?>
                <span class="no-answer">— no answer —</span>
              <?php else: ?>
                <?= nl2br(e($v)) ?>
              <?php endif; ?>
            <?php else: ?>
              <?php $v = is_string($value) ? $value : ''; ?>
              <?php if ($v === ''): ?>
                <span class="no-answer">— no answer —</span>
              <?php elseif ($type === 'email'): ?>
                <span class="mono"><?= e($v) ?></span>
              <?php else: ?>
                <?= e($v) ?>
              <?php endif; ?>
            <?php endif; ?>
          </dd>
        </div>
      <?php endforeach; ?>
    </dl>
    <?php if ($hasFileField): ?>
      <p class="muted small">files are write-only in this playground</p>
    <?php endif; ?>
  </div>

  <div class="detail-side">
    <div class="card">
      <h2>Processing</h2>
      <div class="job-row">
        <span class="job-name">PDF</span>
        <?= status_badge($pdfStatus) ?>
        <?php if ($pdfStatus === 'done'): ?>
          <a class="btn btn-primary btn-small" href="/submissions/<?= (int) $submission['id'] ?>/pdf">Download PDF</a>
        <?php elseif ($pdfStatus === 'failed'): ?>
          <span class="muted small">rendering failed</span>
        <?php else: ?>
          <a class="btn btn-secondary btn-small" href="/submissions/<?= (int) $submission['id'] ?>/pdf">Check progress</a>
        <?php endif; ?>
      </div>
      <?php if ($pdfStatus === 'failed' && !empty($pdfJob['error'])): ?>
        <p class="error-note"><?= e((string) $pdfJob['error']) ?></p>
      <?php endif; ?>
      <div class="job-row">
        <span class="job-name">Email</span>
        <?= status_badge($emailStatus) ?>
        <?php if ($emailRow !== null): ?>
          <span class="muted small mono">to <?= e((string) $emailRow['to_email']) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($emailStatus === 'failed' && !empty($emailRow['error'])): ?>
        <p class="error-note"><?= e((string) $emailRow['error']) ?></p>
      <?php endif; ?>
      <?php if ($autoresponderRow !== null): ?>
        <div class="job-row">
          <span class="job-name">Autoresponder</span>
          <?= status_badge((string) ($autoresponderRow['status'] ?? 'pending')) ?>
          <span class="muted small mono">to <?= e((string) $autoresponderRow['to_email']) ?></span>
        </div>
        <?php if (($autoresponderRow['status'] ?? '') === 'failed' && !empty($autoresponderRow['error'])): ?>
          <p class="error-note"><?= e((string) $autoresponderRow['error']) ?></p>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($webhookRow !== null): ?>
        <div class="job-row">
          <span class="job-name">Webhook</span>
          <span class="badge <?= $webhookBadgeClass ?>"><?= e((string) ($webhookRow['status'] ?? 'pending')) ?></span>
          <?php if (!empty($webhookRow['response_code'])): ?>
            <span class="muted small mono">HTTP <?= (int) $webhookRow['response_code'] ?> · <?= (int) ($webhookRow['attempts'] ?? 0) ?> attempt<?= (int) ($webhookRow['attempts'] ?? 0) === 1 ? '' : 's' ?></span>
          <?php endif; ?>
        </div>
        <?php if (($webhookRow['status'] ?? '') === 'failed' && !empty($webhookRow['error'])): ?>
          <p class="error-note"><?= e((string) $webhookRow['error']) ?></p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Meta</h2>
      <dl class="meta-list">
        <div><dt>Submitted</dt><dd class="mono"><?= e((string) $submission['created_at']) ?> UTC</dd></div>
        <div><dt>IP</dt><dd class="mono"><?= e((string) ($submission['ip'] ?? '—')) ?></dd></div>
        <div><dt>User agent</dt><dd class="break"><?= e((string) ($submission['user_agent'] ?? '—')) ?></dd></div>
      </dl>
    </div>
  </div>
</div>
