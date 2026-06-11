<?php

declare(strict_types=1);

/**
 * GET /v1/forms/{public_id}/submissions?limit=&offset=
 * limit clamped to 1..100 (default 20), offset >= 0 (default 0); total is the
 * unpaged COUNT(*). data is returned as a JSON object, not a string.
 *
 * @var array{id: int} $apiUser
 * @var array<string, string> $params
 */

$form = api_owned_form($apiUser, (string) $params['public_id']);

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
$limit = max(1, min(100, $limit));
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
$offset = max(0, $offset);

$total = (int) (DB::one(
    'SELECT COUNT(*) AS c FROM submissions WHERE form_id = ?',
    [(int) $form['id']]
)['c'] ?? 0);

// $limit/$offset are sanitized ints — interpolation keeps LIMIT/OFFSET typed.
$rows = DB::all(
    "SELECT id, created_at, data FROM submissions WHERE form_id = ?
      ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
    [(int) $form['id']]
);

$submissions = [];
foreach ($rows as $row) {
    $submissions[] = [
        'id' => (int) $row['id'],
        'created_at' => (string) $row['created_at'],
        'data' => api_decode_json_object((string) $row['data']),
    ];
}

api_json(['ok' => true, 'total' => $total, 'submissions' => $submissions]);
