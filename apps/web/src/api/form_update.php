<?php

declare(strict_types=1);

/**
 * PUT /v1/forms/{public_id} — replace title/description/fields/conditions/
 * webhook_url for an owned form. Same validators as the web builder. Returns
 * the updated form. Settings (publish/limit/thank-you/autoresponder) are a
 * separate endpoint.
 *
 * @var array{id: int} $apiUser
 * @var array<string, string> $params
 */

$form = api_owned_form($apiUser, (string) $params['public_id']);
$body = api_json_body();

$check = validate_builder_request(api_builder_post_from_body($body));
if (!$check['ok']) {
    api_error('validation_failed', (string) $check['error'], 422);
}

$conditionsJson = json_encode($body['conditions'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$conditionsCheck = conditions_validate($conditionsJson, $check['fields']);
if (!$conditionsCheck['ok']) {
    api_error('validation_failed', (string) $conditionsCheck['error'], 422);
}

DB::run(
    'UPDATE forms SET title = ?, description = ?, fields = ?, conditions = ?, webhook_url = ? WHERE id = ?',
    [
        $check['title'],
        $check['description'],
        json_encode($check['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $conditionsCheck['conditions'] === []
            ? null
            : json_encode($conditionsCheck['conditions'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $check['webhook_url'],
        (int) $form['id'],
    ]
);

invalidate_form_cache((string) $form['public_id']);
Metrics::increment('web.form.update');

$updated = DB::one('SELECT * FROM forms WHERE id = ?', [(int) $form['id']]);
api_json(['ok' => true, 'form' => api_form_payload($updated ?? [])]);
