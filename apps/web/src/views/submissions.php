<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $form
 * @var list<array<string,mixed>> $rows
 */
?>
<div class="page-head">
  <div>
    <h1>Submissions</h1>
    <p class="muted"><?= e((string) $form['title']) ?> · <?= count($rows) ?> total</p>
  </div>
  <div class="page-head-actions">
    <a class="btn btn-ghost" href="/forms/<?= (int) $form['id'] ?>/export.csv">Export CSV</a>
    <a class="btn btn-ghost" href="<?= e(public_form_url((string) $form['public_id'])) ?>" target="_blank" rel="noopener">Open public form</a>
    <a class="btn btn-ghost" href="/dashboard">&larr; Dashboard</a>
  </div>
</div>

<?php if ($rows === []): ?>
  <div class="card empty-state">
    <h2>No submissions yet</h2>
    <p>Share the public link and responses will appear here:</p>
    <p>
      <code><?= e(public_form_url((string) $form['public_id'])) ?></code>
      <button type="button" class="btn btn-ghost btn-small" data-copy="<?= e(public_form_url((string) $form['public_id'])) ?>">Copy</button>
    </p>
  </div>
<?php else: ?>
  <div class="card table-card">
    <table class="table">
      <thead>
        <tr>
          <th>#</th>
          <th>Submitted</th>
          <th>PDF</th>
          <th class="th-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><a href="/submissions/<?= (int) $row['id'] ?>">#<?= (int) $row['id'] ?></a></td>
            <td><?= e((string) $row['created_at']) ?> UTC</td>
            <td><?= status_badge((string) ($row['pdf_status'] ?? 'pending')) ?></td>
            <td class="cell-actions">
              <a class="btn btn-ghost btn-small" href="/submissions/<?= (int) $row['id'] ?>">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
