<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $form
 * @var array{is_published: int, submission_limit: string, thankyou_message: string, autoresponder_enabled: int} $values
 */
$embedSnippet = '<iframe src="' . public_form_url((string) $form['public_id'])
    . '/embed" width="100%" height="600" style="border:none;"></iframe>';
?>
<div class="page-head">
  <div>
    <h1>Form settings</h1>
    <p class="muted"><?= e((string) $form['title']) ?></p>
  </div>
  <div class="page-head-actions">
    <a class="btn btn-secondary" href="/forms/<?= (int) $form['id'] ?>/submissions">Inbox</a>
    <a class="btn btn-secondary" href="/forms/<?= (int) $form['id'] ?>/edit">Edit fields</a>
    <a class="back-link" href="/dashboard">&larr; Dashboard</a>
  </div>
</div>

<div class="card">
  <form method="post" action="/forms/<?= (int) $form['id'] ?>/settings">
    <?= Csrf::field() ?>

    <div class="settings-section">
      <p class="section-label">Publishing</p>
      <div class="form-row">
        <label class="switch-row">
          <input type="checkbox" name="is_published" value="1" <?= (int) $values['is_published'] === 1 ? 'checked' : '' ?>>
          <span class="switch-track" aria-hidden="true"></span>
          <span>Published</span>
        </label>
        <p class="hint">Unchecked, the public link shows a friendly "closed" page and stops accepting responses.</p>
      </div>
      <div class="form-row">
        <label for="settings-limit">Submission limit <span class="muted">(optional)</span></label>
        <input class="input" id="settings-limit" type="number" name="submission_limit"
               min="1" max="65535" step="1" placeholder="unlimited"
               value="<?= e($values['submission_limit']) ?>">
        <p class="hint">The form closes once it has this many responses · leave empty for unlimited.</p>
      </div>
    </div>

    <div class="settings-section">
      <p class="section-label">Thank-you page</p>
      <div class="form-row">
        <label for="settings-thankyou">Thank-you message <span class="muted">(optional)</span></label>
        <textarea class="input" id="settings-thankyou" name="thankyou_message" rows="3" maxlength="500"
                  placeholder="Shown on the thank-you page instead of the default copy"><?= e($values['thankyou_message']) ?></textarea>
        <p class="hint">500 characters max · leave empty for the default copy.</p>
      </div>
    </div>

    <div class="settings-section">
      <p class="section-label">Autoresponder</p>
      <div class="form-row">
        <label class="switch-row">
          <input type="checkbox" name="autoresponder_enabled" value="1" <?= (int) $values['autoresponder_enabled'] === 1 ? 'checked' : '' ?>>
          <span class="switch-track" aria-hidden="true"></span>
          <span>Autoresponder email</span>
        </label>
        <p class="hint">Emails a thank-you (with their answers) to the address in the form's first email field.</p>
      </div>
    </div>

    <div class="settings-actions">
      <button type="submit" class="btn btn-primary">Save settings</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-title-row">
    <h2>Embed</h2>
    <button type="button" class="btn btn-secondary btn-small" data-copy="<?= e($embedSnippet) ?>">Copy snippet</button>
  </div>
  <p class="hint" style="margin-top:0">Paste this snippet into any page — the form renders chrome-less inside an iframe.</p>
  <div class="form-row">
    <label class="visually-hidden" for="embed-snippet">Embed snippet</label>
    <textarea class="input snippet-box" id="embed-snippet" rows="2" readonly><?= e($embedSnippet) ?></textarea>
  </div>
</div>

<div class="card danger-zone">
  <h2>Danger zone</h2>
  <div class="danger-zone-row">
    <p class="muted small">Deleting this form also deletes all of its submissions, PDFs and job history. There is no undo.</p>
    <form method="post" action="/forms/<?= (int) $form['id'] ?>/delete" class="inline-form"
          data-confirm="Delete &quot;<?= e((string) $form['title']) ?>&quot; and all of its submissions?">
      <?= Csrf::field() ?>
      <button type="submit" class="btn btn-danger">Delete this form</button>
    </form>
  </div>
</div>
