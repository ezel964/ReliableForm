<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $forms
 * @var array<string,int>|null $viewCounts keyed by public_id; null ⇒ Redis down
 * @var string $apiKey
 */
$apiBase = rtrim((string) Config::get('APP_URL', 'http://localhost:8080'), '/');
$curlExample = 'curl -H "Authorization: Bearer ' . $apiKey . '" ' . $apiBase . '/v1/forms';

// Aggregates for the stat strip — all derived from already-loaded data.
$totalSubmissions = 0;
foreach ($forms as $f) {
    $totalSubmissions += (int) $f['submission_count'];
}
$totalViews = null;
if ($viewCounts !== null) {
    $totalViews = 0;
    foreach ($forms as $f) {
        $totalViews += (int) ($viewCounts[(string) $f['public_id']] ?? 0);
    }
}
?>
<div class="page-head">
  <div>
    <h1>Your forms</h1>
    <p class="muted">Everything you've built, and how it's doing.</p>
  </div>
  <div class="page-head-actions">
    <a class="btn btn-primary" href="/forms/new">+ New form</a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat">
    <div class="stat-label">Forms</div>
    <div class="stat-value"><?= count($forms) ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">Submissions</div>
    <div class="stat-value"><?= $totalSubmissions ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">Views</div>
    <div class="stat-value"><?= $totalViews === null ? '—' : $totalViews ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">Conversion</div>
    <div class="stat-value"><?= e(conversion_label($totalSubmissions, $totalViews)) ?></div>
  </div>
</div>

<?php if ($forms === []): ?>
  <div class="card empty-state">
    <div class="empty-glyph" aria-hidden="true">⊞</div>
    <h2>No forms yet</h2>
    <p>Create your first form, share its public link, and responses will show up
       right here — with a PDF and an email alert for each one.</p>
    <p><a class="btn btn-primary" href="/forms/new">Create your first form</a></p>
  </div>
<?php else: ?>
  <div class="card table-card">
    <table class="table">
      <thead>
        <tr>
          <th scope="col">Form</th>
          <th scope="col">Submissions</th>
          <th scope="col">Views</th>
          <th scope="col">Conversion</th>
          <th scope="col">Public link</th>
          <th scope="col" class="th-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($forms as $form): ?>
          <?php
          $url = public_form_url((string) $form['public_id']);
          $views = $viewCounts === null ? null : (int) ($viewCounts[(string) $form['public_id']] ?? 0);
          ?>
          <tr>
            <td>
              <strong><?= e((string) $form['title']) ?></strong>
              <div class="muted small">created <?= e((string) $form['created_at']) ?></div>
            </td>
            <td>
              <a href="/forms/<?= (int) $form['id'] ?>/submissions">
                <?= (int) $form['submission_count'] ?> submission<?= (int) $form['submission_count'] === 1 ? '' : 's' ?>
              </a>
            </td>
            <td><?= $views === null ? '—' : $views ?></td>
            <td><?= e(conversion_label((int) $form['submission_count'], $views)) ?></td>
            <td class="cell-link">
              <a href="<?= e($url) ?>" target="_blank" rel="noopener">/f/<?= e((string) $form['public_id']) ?></a>
              <button type="button" class="btn btn-ghost btn-small" data-copy="<?= e($url) ?>">Copy</button>
            </td>
            <td class="cell-actions">
              <a class="btn btn-ghost btn-small" href="/forms/<?= (int) $form['id'] ?>/edit">Edit</a>
              <a class="btn btn-ghost btn-small" href="/forms/<?= (int) $form['id'] ?>/settings">Settings</a>
              <a class="btn btn-ghost btn-small" href="/forms/<?= (int) $form['id'] ?>/submissions">Inbox</a>
              <form method="post" action="/forms/<?= (int) $form['id'] ?>/delete" class="inline-form"
                    data-confirm="Delete &quot;<?= e((string) $form['title']) ?>&quot; and all of its submissions?">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-danger btn-small">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-title-row">
    <h2>API access</h2>
    <span class="badge badge-pending">REST v1</span>
  </div>
  <p class="muted small">Use this key with the REST API v1 (<code>Authorization: Bearer …</code> or <code>X-Api-Key</code>).</p>
  <div class="form-row">
    <label for="api-key">Your API key</label>
    <div class="key-row">
      <input id="api-key" class="input" type="text" readonly value="<?= e($apiKey) ?>">
      <button type="button" class="btn btn-secondary btn-small" data-copy="<?= e($apiKey) ?>">Copy</button>
    </div>
  </div>
  <div class="form-row">
    <label for="api-example">Example</label>
    <p class="break" id="api-example"><code><?= e($curlExample) ?></code></p>
  </div>
  <form method="post" action="/account/api-key" class="inline-form"
        data-confirm="Regenerate your API key? The current key stops working immediately.">
    <?= Csrf::field() ?>
    <button type="submit" class="btn btn-danger btn-small">Regenerate key</button>
  </form>
  <p class="hint">Regenerating invalidates the old key immediately — update any integrations that use it.</p>
</div>
