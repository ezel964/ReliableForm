<?php

declare(strict_types=1);

$user = Auth::requireLogin();

// API key for the "API access" card. Legacy edge: rows created before the
// api_key column heal lazily — generate and persist one on first sight.
$keyRow = DB::one('SELECT api_key FROM users WHERE id = ?', [(int) $user['id']]);
$apiKey = (string) ($keyRow['api_key'] ?? '');
if ($apiKey === '') {
    $apiKey = generate_api_key();
    DB::run('UPDATE users SET api_key = ? WHERE id = ?', [$apiKey, (int) $user['id']]);
}

// One aggregated query: every form + its submission count.
$forms = DB::all(
    'SELECT f.id, f.public_id, f.title, f.created_at, COUNT(s.id) AS submission_count
       FROM forms f
       LEFT JOIN submissions s ON s.form_id = f.id
      WHERE f.user_id = ?
      GROUP BY f.id
      ORDER BY f.created_at DESC, f.id DESC',
    [(int) $user['id']]
);

render_page('dashboard', [
    'title' => 'Dashboard',
    'currentUser' => $user,
    'forms' => $forms,
    'apiKey' => $apiKey,
]);
