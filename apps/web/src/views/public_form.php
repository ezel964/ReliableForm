<?php

declare(strict_types=1);

/**
 * Public form page — works fully without JavaScript (logic rules then apply
 * server-side only; app.js mirrors them live in the browser).
 * @var array<string,mixed> $form
 * @var list<array<string,mixed>> $fields
 * @var list<array<string,mixed>> $conditions logic rules for the JS engine
 * @var array<string,string> $errors  field_id => message
 * @var array<string,mixed> $old      field_id => previous value(s)
 * @var string $publicId
 * @var bool $embed chrome-less iframe variant
 */
$conditions = $conditions ?? [];
$embed = $embed ?? false;
$hasFileField = false;
foreach ($fields as $f) {
    if (($f['type'] ?? '') === 'file') {
        $hasFileField = true;
        break;
    }
}
?>
<div class="public-form card">
  <h1><?= e((string) $form['title']) ?></h1>
  <?php if (!empty($form['description'])): ?>
    <p class="form-description"><?= nl2br(e((string) $form['description'])) ?></p>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="flash flash-error" role="alert">Please fix the highlighted answers below.</div>
  <?php endif; ?>

  <form method="post" action="/f/<?= e($publicId) ?>" class="stack"<?= $hasFileField ? ' enctype="multipart/form-data"' : '' ?>>
    <?= Csrf::field() ?>
    <?php if ($embed): ?>
      <input type="hidden" name="embed" value="1">
    <?php endif; ?>
    <?php /* Honeypot — invisible to people, irresistible to bots. */ ?>
    <div class="hp" aria-hidden="true">
      <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    </div>

    <?php foreach ($fields as $field): ?>
      <?php
      $fid = (string) ($field['id'] ?? '');
      $type = (string) ($field['type'] ?? 'text');
      $label = (string) ($field['label'] ?? '');
      $required = !empty($field['required']);
      $placeholder = (string) ($field['placeholder'] ?? '');
      $options = is_array($field['options'] ?? null) ? $field['options'] : [];
      $error = $errors[$fid] ?? null;
      $value = $old[$fid] ?? ($type === 'checkbox' ? [] : '');
      ?>
      <div class="form-row<?= $error !== null ? ' has-error' : '' ?>" data-field="<?= e($fid) ?>">
        <?php if ($type === 'radio' || $type === 'checkbox' || $type === 'rating'): ?>
          <fieldset>
            <legend><?= e($label) ?><?php if ($required): ?><span class="req-star"> *</span><?php endif; ?></legend>

            <?php if ($type === 'rating'): ?>
              <?php $max = (int) ($field['max'] ?? 5); ?>
              <div class="stars">
                <?php for ($i = $max; $i >= 1; $i--): ?>
                  <input type="radio"
                         id="<?= e($fid) ?>_<?= $i ?>"
                         name="answers[<?= e($fid) ?>]"
                         value="<?= $i ?>"
                         <?= (string) $value === (string) $i ? 'checked' : '' ?>
                         <?= $required ? 'required' : '' ?>>
                  <label for="<?= e($fid) ?>_<?= $i ?>" aria-label="<?= $i ?> of <?= $max ?> stars">★</label>
                <?php endfor; ?>
              </div>
            <?php elseif ($type === 'radio'): ?>
              <?php foreach ($options as $j => $option): ?>
                <label class="choice">
                  <input type="radio"
                         name="answers[<?= e($fid) ?>]"
                         value="<?= e((string) $option) ?>"
                         <?= $value === $option ? 'checked' : '' ?>
                         <?= $required ? 'required' : '' ?>>
                  <span><?= e((string) $option) ?></span>
                </label>
              <?php endforeach; ?>
            <?php else: /* checkbox */ ?>
              <?php foreach ($options as $option): ?>
                <label class="choice">
                  <input type="checkbox"
                         name="answers[<?= e($fid) ?>][]"
                         value="<?= e((string) $option) ?>"
                         <?= is_array($value) && in_array($option, $value, true) ? 'checked' : '' ?>>
                  <span><?= e((string) $option) ?></span>
                </label>
              <?php endforeach; ?>
            <?php endif; ?>
          </fieldset>
        <?php else: ?>
          <label for="fld-<?= e($fid) ?>"><?= e($label) ?><?php if ($required): ?><span class="req-star"> *</span><?php endif; ?></label>

          <?php if ($type === 'textarea'): ?>
            <textarea class="input" id="fld-<?= e($fid) ?>" name="answers[<?= e($fid) ?>]" rows="4"
                      placeholder="<?= e($placeholder) ?>" <?= $required ? 'required' : '' ?>><?= e(is_string($value) ? $value : '') ?></textarea>
          <?php elseif ($type === 'file'): ?>
            <input class="input" id="fld-<?= e($fid) ?>" type="file"
                   name="files[<?= e($fid) ?>]" <?= $required ? 'required' : '' ?>>
            <p class="hint">pdf png jpg jpeg gif txt csv zip · max 2 MB</p>
          <?php elseif ($type === 'select'): ?>
            <select class="input" id="fld-<?= e($fid) ?>" name="answers[<?= e($fid) ?>]" <?= $required ? 'required' : '' ?>>
              <option value="">Choose…</option>
              <?php foreach ($options as $option): ?>
                <option value="<?= e((string) $option) ?>" <?= $value === $option ? 'selected' : '' ?>><?= e((string) $option) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <?php $inputType = in_array($type, ['email', 'number', 'date'], true) ? $type : 'text'; ?>
            <input class="input" id="fld-<?= e($fid) ?>"
                   type="<?= $inputType ?>"
                   <?= $inputType === 'number' ? 'step="any"' : '' ?>
                   name="answers[<?= e($fid) ?>]"
                   placeholder="<?= e($placeholder) ?>"
                   value="<?= e(is_string($value) ? $value : '') ?>"
                   <?= $required ? 'required' : '' ?>>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($error !== null): ?>
          <p class="field-error"><?= e($error) ?></p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <button type="submit" class="btn btn-primary btn-large">Submit</button>
  </form>
</div>
<?php if ($conditions !== []): ?>
<script type="application/json" id="rf-conditions"><?= json_encode(
    $conditions,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
) ?: '[]' ?></script>
<?php endif; ?>
