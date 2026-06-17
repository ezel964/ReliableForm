<?php

declare(strict_types=1);

/**
 * GET /v1/forms/{public_id}/settings — publish state, submission limit,
 * thank-you message, autoresponder toggle for an owned form.
 *
 * @var array{id: int} $apiUser
 * @var array<string, string> $params
 */

$form = api_owned_form($apiUser, (string) $params['public_id']);

api_json([
    'ok' => true,
    'settings' => [
        'is_published' => (int) $form['is_published'] === 1,
        'submission_limit' => $form['submission_limit'] !== null ? (int) $form['submission_limit'] : null,
        'thankyou_message' => $form['thankyou_message'] !== null ? (string) $form['thankyou_message'] : null,
        'autoresponder_enabled' => (int) $form['autoresponder_enabled'] === 1,
    ],
]);
