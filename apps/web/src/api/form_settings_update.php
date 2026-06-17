<?php

declare(strict_types=1);

/**
 * PUT /v1/forms/{public_id}/settings — update publish/limit/thank-you/
 * autoresponder. Reuses validate_settings_request() (validation.php), so the
 * rules match the web settings page. Returns the saved settings.
 *
 * @var array{id: int} $apiUser
 * @var array<string, string> $params
 */

$form = api_owned_form($apiUser, (string) $params['public_id']);
$body = api_json_body();

$check = validate_settings_request(api_settings_post_from_body($body));
if (!$check['ok']) {
    api_error('validation_failed', (string) $check['error'], 422);
}

DB::run(
    'UPDATE forms SET is_published = ?, submission_limit = ?, thankyou_message = ?, autoresponder_enabled = ? WHERE id = ?',
    [
        $check['is_published'],
        $check['submission_limit'],
        $check['thankyou_message'],
        $check['autoresponder_enabled'],
        (int) $form['id'],
    ]
);

invalidate_form_cache((string) $form['public_id']);
Metrics::increment('web.form.update');

api_json([
    'ok' => true,
    'settings' => [
        'is_published' => $check['is_published'] === 1,
        'submission_limit' => $check['submission_limit'],
        'thankyou_message' => $check['thankyou_message'],
        'autoresponder_enabled' => $check['autoresponder_enabled'] === 1,
    ],
]);
