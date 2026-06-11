<?php

declare(strict_types=1);

/**
 * GET /v1/forms/{public_id} — full definition including the fields array.
 * @var array{id: int} $apiUser
 * @var array<string, string> $params
 */

$form = api_owned_form($apiUser, (string) $params['public_id']);

api_json([
    'ok' => true,
    'form' => [
        'id' => (int) $form['id'],
        'public_id' => (string) $form['public_id'],
        'title' => (string) $form['title'],
        'description' => $form['description'] !== null ? (string) $form['description'] : null,
        'fields' => api_decode_fields((string) $form['fields']),
        'is_published' => (int) $form['is_published'] === 1,
        'webhook_url' => $form['webhook_url'] !== null ? (string) $form['webhook_url'] : null,
        'created_at' => (string) $form['created_at'],
        'updated_at' => (string) $form['updated_at'],
    ],
]);
