<?php

declare(strict_types=1);

/**
 * Form builder (new + edit modes). builder.js owns the field list; the state
 * is serialized into the hidden fields_json input on submit. Initial state is
 * embedded as JSON in a <script type="application/json"> tag (never inline JS).
 *
 * @var string $mode 'new'|'edit'
 * @var string $action
 * @var string $titleValue
 * @var string $descriptionValue
 * @var string $webhookValue
 * @var array $fields
 * @var array $conditions logic rules, serialized into hidden conditions_json
 */
$webhookValue = $webhookValue ?? '';
$conditions = $conditions ?? [];
?>
<div class="page-head">
  <h1><?= $mode === 'edit' ? 'Edit form' : 'New form' ?></h1>
  <a class="btn btn-ghost" href="/dashboard">&larr; Back to dashboard</a>
</div>

<noscript>
  <div class="flash flash-error">
    The form builder needs JavaScript. Everything else on ReliableForm works without it.
  </div>
</noscript>

<form method="post" action="<?= e($action) ?>" id="builder-form">
  <?= Csrf::field() ?>
  <input type="hidden" name="fields_json" id="fields_json" value="">
  <input type="hidden" name="conditions_json" id="conditions_json" value="">

  <div class="card builder-head">
    <div class="form-row">
      <label for="form-title">Form title</label>
      <input class="input input-title" id="form-title" type="text" name="title" maxlength="200" required
             placeholder="e.g. Customer Feedback" value="<?= e($titleValue) ?>">
    </div>
    <div class="form-row">
      <label for="form-description">Description <span class="muted">(optional, shown to respondents)</span></label>
      <textarea class="input" id="form-description" name="description" rows="2" maxlength="2000"
                placeholder="A sentence or two about this form"><?= e($descriptionValue) ?></textarea>
    </div>
    <h2>Integrations</h2>
    <div class="form-row">
      <label for="form-webhook">Webhook URL <span class="muted">(optional)</span></label>
      <input class="input" id="form-webhook" type="url" name="webhook_url" maxlength="500"
             placeholder="https://example.com/hook" value="<?= e($webhookValue) ?>">
      <p class="hint">POSTs each submission as JSON · leave empty to disable; test sink: <code>php -S 127.0.0.1:9444 tools/webhook-sink.php</code></p>
    </div>
    <div class="builder-save">
      <button type="submit" class="btn btn-primary"><?= $mode === 'edit' ? 'Save changes' : 'Create form' ?></button>
    </div>
  </div>

  <div class="builder-cols">
    <aside class="card palette">
      <h2>Add a field</h2>
      <div class="palette-grid" id="palette-buttons"></div>
    </aside>
    <div class="field-list" id="field-list"></div>
  </div>

  <div class="card" id="logic-card">
    <h2>Logic</h2>
    <p class="hint">Show or hide a field depending on another field's answer · 20 rules max.</p>
    <div id="logic-rules"></div>
    <button type="button" class="btn btn-ghost btn-small" id="logic-add">+ Add rule</button>
  </div>
</form>

<script type="application/json" id="builder-initial"><?= json_encode(
    $fields,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
) ?: '[]' ?></script>
<script type="application/json" id="builder-conditions-initial"><?= json_encode(
    $conditions,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
) ?: '[]' ?></script>
