<?php

declare(strict_types=1);

$user = Auth::requireLogin();
$form = owned_form($user, (int) $params['id']);

$rows = DB::all(
    'SELECT s.id, s.created_at, p.status AS pdf_status
       FROM submissions s
       LEFT JOIN pdf_jobs p ON p.submission_id = s.id
      WHERE s.form_id = ?
      ORDER BY s.created_at DESC, s.id DESC',
    [(int) $form['id']]
);

render_page('submissions', [
    'title' => 'Submissions — ' . (string) $form['title'],
    'currentUser' => $user,
    'form' => $form,
    'rows' => $rows,
]);
