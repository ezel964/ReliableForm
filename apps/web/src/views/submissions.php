<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $form
 * @var list<array<string,mixed>> $rows
 * @var string $q
 * @var int $page
 * @var int $lastPage
 * @var int $perPage
 * @var int $total matches of the current search (= all when no search)
 * @var int $totalAll all submissions on this form
 * @var int|null $views null ⇒ Redis down
 */
$formId = (int) $form['id'];
$pageUrl = static function (int $p) use ($formId, $q): string {
    $query = ['page' => (string) $p];
    if ($q !== '') {
        $query['q'] = $q;
    }
    return '/forms/' . $formId . '/submissions?' . http_build_query($query);
};
$from = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$to = min($page * $perPage, $total);
?>
<div class="page-head">
  <div>
    <h1>Submissions</h1>
    <p class="muted">
      <?= e((string) $form['title']) ?>
      · <?= $views === null ? '—' : (int) $views ?> view<?= $views === 1 ? '' : 's' ?>
      · <?= $totalAll ?> submission<?= $totalAll === 1 ? '' : 's' ?>
      · <?= e(conversion_label($totalAll, $views)) ?> conversion
    </p>
  </div>
  <div class="page-head-actions">
    <a class="btn btn-ghost" href="/forms/<?= $formId ?>/export.csv">Export CSV</a>
    <a class="btn btn-ghost" href="/forms/<?= $formId ?>/settings">Settings</a>
    <a class="btn btn-ghost" href="<?= e(public_form_url((string) $form['public_id'])) ?>" target="_blank" rel="noopener">Open public form</a>
    <a class="btn btn-ghost" href="/dashboard">&larr; Dashboard</a>
  </div>
</div>

<?php if ($totalAll > 0 || $q !== ''): ?>
  <form method="get" action="/forms/<?= $formId ?>/submissions" class="card search-bar" style="display:flex;gap:10px;align-items:center;padding:14px 20px;margin-bottom:18px">
    <input class="input" type="search" name="q" placeholder="Search answers…" value="<?= e($q) ?>" style="flex:1">
    <button type="submit" class="btn btn-ghost">Search</button>
    <?php if ($q !== ''): ?>
      <a class="btn btn-ghost btn-small" href="/forms/<?= $formId ?>/submissions">Clear</a>
    <?php endif; ?>
  </form>
  <?php if ($q !== ''): ?>
    <p class="muted small" style="margin-bottom:18px">
      Searching for &ldquo;<?= e($q) ?>&rdquo; — the CSV export always includes all <?= $totalAll ?> submissions.
    </p>
  <?php endif; ?>
<?php endif; ?>

<?php if ($rows === []): ?>
  <?php if ($q !== ''): ?>
    <div class="card empty-state">
      <h2>Nothing matches your search</h2>
      <p>No submission contains &ldquo;<?= e($q) ?>&rdquo;.</p>
      <p><a class="btn btn-ghost" href="/forms/<?= $formId ?>/submissions">Show all submissions</a></p>
    </div>
  <?php else: ?>
    <div class="card empty-state">
      <h2>No submissions yet</h2>
      <p>Share the public link and responses will appear here:</p>
      <p>
        <code><?= e(public_form_url((string) $form['public_id'])) ?></code>
        <button type="button" class="btn btn-ghost btn-small" data-copy="<?= e(public_form_url((string) $form['public_id'])) ?>">Copy</button>
      </p>
    </div>
  <?php endif; ?>
<?php else: ?>
  <div class="card table-card">
    <table class="table">
      <thead>
        <tr>
          <th>#</th>
          <th>Submitted</th>
          <th>Answers</th>
          <th>PDF</th>
          <th class="th-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><a href="/submissions/<?= (int) $row['id'] ?>">#<?= (int) $row['id'] ?></a></td>
            <td><?= e((string) $row['created_at']) ?> UTC</td>
            <td class="muted small"><?= e((string) ($row['preview'] ?? '')) ?></td>
            <td><?= status_badge((string) ($row['pdf_status'] ?? 'pending')) ?></td>
            <td class="cell-actions">
              <a class="btn btn-ghost btn-small" href="/submissions/<?= (int) $row['id'] ?>">View</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="page-head" style="margin-top:18px">
    <p class="muted small">Showing <?= $from ?>&ndash;<?= $to ?> of <?= $total ?></p>
    <div class="page-head-actions">
      <?php if ($page > 1): ?>
        <a class="btn btn-ghost btn-small" href="<?= e($pageUrl($page - 1)) ?>">&larr; Newer</a>
      <?php endif; ?>
      <?php if ($page < $lastPage): ?>
        <a class="btn btn-ghost btn-small" href="<?= e($pageUrl($page + 1)) ?>">Older &rarr;</a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
