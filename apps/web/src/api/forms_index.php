<?php

declare(strict_types=1);

/**
 * GET /v1/forms — the owner's forms with submission counts.
 * @var array{id: int} $apiUser
 */

$rows = DB::all(
    'SELECT f.id, f.public_id, f.title, f.is_published, f.webhook_url, f.created_at, f.updated_at,
            (SELECT COUNT(*) FROM submissions s WHERE s.form_id = f.id) AS submissions
       FROM forms f
      WHERE f.user_id = ?
      ORDER BY f.created_at DESC, f.id DESC',
    [$apiUser['id']]
);

$forms = [];
foreach ($rows as $row) {
    $forms[] = [
        'id' => (int) $row['id'],
        'public_id' => (string) $row['public_id'],
        'title' => (string) $row['title'],
        'is_published' => (int) $row['is_published'] === 1,
        'submissions' => (int) $row['submissions'],
        'webhook_url' => $row['webhook_url'] !== null ? (string) $row['webhook_url'] : null,
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
    ];
}

api_json(['ok' => true, 'forms' => $forms]);
