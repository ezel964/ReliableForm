<?php

declare(strict_types=1);

$user = Auth::requireLogin();
$form = owned_form($user, (int) $params['id']);

$perPage = 25;

$q = $_GET['q'] ?? '';
$q = is_string($q) ? trim($q) : '';

$page = $_GET['page'] ?? '1';
$page = (is_string($page) && ctype_digit($page)) ? max(1, (int) $page) : 1;

// ?q= searches the submission JSON text. LIKE wildcards in the query are
// literals to the searcher — escape % _ (and \, MySQL's LIKE escape char).
$where = 's.form_id = ?';
$args = [(int) $form['id']];
if ($q !== '') {
    $where .= ' AND s.data LIKE ?';
    $args[] = '%' . addcslashes($q, '\\%_') . '%';
}

$countRow = DB::one("SELECT COUNT(*) AS c FROM submissions s WHERE {$where}", $args);
$total = (int) ($countRow['c'] ?? 0);
$lastPage = max(1, (int) ceil($total / $perPage));
if ($page > $lastPage) {
    $page = $lastPage; // past the end ⇒ clamp to the last page
}
$offset = ($page - 1) * $perPage;

// LIMIT/OFFSET are server-computed ints — interpolated, never user input.
$rows = DB::all(
    "SELECT s.id, s.created_at, s.data, p.status AS pdf_status
       FROM submissions s
       LEFT JOIN pdf_jobs p ON p.submission_id = s.id
      WHERE {$where}
      ORDER BY s.created_at DESC, s.id DESC
      LIMIT {$perPage} OFFSET {$offset}",
    $args
);

// Answer previews in field order (first ~2 answers per row).
$fields = json_decode((string) $form['fields'], true);
if (!is_array($fields)) {
    $fields = [];
}
foreach ($rows as $i => $row) {
    $data = json_decode((string) $row['data'], true);
    $rows[$i]['preview'] = submission_preview($fields, is_array($data) ? $data : []);
}

// Header stats: views are Redis-only; null ⇒ Redis down ⇒ '—' in the view.
$publicId = (string) $form['public_id'];
$viewCounts = form_view_counts([$publicId]);
$views = $viewCounts[$publicId] ?? null;
$totalAll = $q === ''
    ? $total
    : (int) (DB::one('SELECT COUNT(*) AS c FROM submissions WHERE form_id = ?', [(int) $form['id']])['c'] ?? 0);

render_page('submissions', [
    'title' => 'Submissions — ' . (string) $form['title'],
    'currentUser' => $user,
    'form' => $form,
    'rows' => $rows,
    'q' => $q,
    'page' => $page,
    'lastPage' => $lastPage,
    'perPage' => $perPage,
    'total' => $total,
    'totalAll' => $totalAll,
    'views' => $views,
]);
