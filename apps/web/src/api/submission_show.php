<?php

declare(strict_types=1);

/**
 * GET /v1/submissions/{id} — a single submission (owner-scoped via its form).
 * Returns the answers plus light form context so the dashboard can render a
 * detail view from a cold deep-link. Missing or not-owned => 404 (no leak).
 *
 * @var array{id: int} $apiUser
 * @var array<string, string> $params
 */

$row = DB::one(
    'SELECT s.id, s.created_at, s.data, f.public_id AS form_public_id, f.title AS form_title, f.fields
       FROM submissions s
       JOIN forms f ON f.id = s.form_id
      WHERE s.id = ? AND f.user_id = ?',
    [(int) $params['id'], (int) $apiUser['id']]
);
if ($row === null) {
    api_error('not_found', 'That submission does not exist.', 404);
}

api_json([
    'ok' => true,
    'submission' => [
        'id' => (int) $row['id'],
        'created_at' => (string) $row['created_at'],
        'data' => api_decode_json_object((string) $row['data']),
        'form' => [
            'public_id' => (string) $row['form_public_id'],
            'title' => (string) $row['form_title'],
            'fields' => api_decode_fields((string) $row['fields']),
        ],
    ],
]);
