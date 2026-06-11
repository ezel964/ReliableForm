<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $form
 * @var array{is_published: int, submission_limit: string, thankyou_message: string, autoresponder_enabled: int} $values
 */
?>
<div class="page-head">
  <div>
    <h1>Form settings</h1>
    <p class="muted"><?= e((string) $form['title']) ?></p>
  </div>
  <div class="page-head-actions">
    <a class="btn btn-ghost" href="/forms/<?= (int) $form['id'] ?>/submissions">Submissions</a>
    <a class="btn btn-ghost" href="/forms/<?= (int) $form['id'] ?>/edit">Edit fields</a>
    <a class="btn btn-ghost" href="/dashboard">&larr; Dashboard</a>
  </div>
</div>

<div class="card">
  <form method="post" action="/forms/<?= (int) $form['id'] ?>/settings" class="stack">
    <?= Csrf::field() ?>

    <div class="form-row">
      <label class="choice">
        <input type="checkbox" name="is_published" value="1" <?= (int) $values['is_published'] === 1 ? 'checked' : '' ?>>
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

    <div class="form-row">
      <label for="settings-thankyou">Thank-you message <span class="muted">(optional)</span></label>
      <textarea class="input" id="settings-thankyou" name="thankyou_message" rows="3" maxlength="500"
                placeholder="Shown on the thank-you page instead of the default copy"><?= e($values['thankyou_message']) ?></textarea>
      <p class="hint">500 characters max · leave empty for the default copy.</p>
    </div>

    <div class="form-row">
      <label class="choice">
        <input type="checkbox" name="autoresponder_enabled" value="1" <?= (int) $values['autoresponder_enabled'] === 1 ? 'checked' : '' ?>>
        <span>Autoresponder email</span>
      </label>
      <p class="hint">Emails a thank-you (with their answers) to the address in the form's first email field.</p>
    </div>

    <button type="submit" class="btn btn-primary">Save settings</button>
  </form>
</div>
