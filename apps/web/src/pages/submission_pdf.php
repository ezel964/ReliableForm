<?php

declare(strict_types=1);

$user = Auth::requireLogin();
$submissionId = (int) $params['id']; // router guarantees \d+

$row = DB::one(
    'SELECT s.id, f.user_id AS owner_id
       FROM submissions s
       JOIN forms f ON f.id = s.form_id
      WHERE s.id = ?',
    [$submissionId]
);
if ($row === null || (int) $row['owner_id'] !== (int) $user['id']) {
    not_found('That submission does not exist.');
}

$job = DB::one('SELECT status, file_path, error FROM pdf_jobs WHERE submission_id = ?', [$submissionId]);
$status = (string) ($job['status'] ?? 'pending'); // no row yet == still queued

if ($status === 'done') {
    // Stream ONLY the DB-stored path, and only after proving it lives in storage/pdfs/.
    $filePath = (string) ($job['file_path'] ?? '');
    $base = realpath(RF_ROOT . '/storage/pdfs');
    // file_path is stored relative to the project root; resolve it there, never
    // against the process cwd (php -S may be launched from anywhere).
    $absolute = str_starts_with($filePath, '/') ? $filePath : RF_ROOT . '/' . $filePath;
    $real = $filePath !== '' ? realpath($absolute) : false;

    if ($base === false || $real === false
        || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)
        || !is_file($real)) {
        render_page('pdf_wait', [
            'title' => 'PDF unavailable',
            'currentUser' => $user,
            'failed' => true,
            'error' => 'The PDF was marked done but its file is missing from storage.',
            'submissionId' => $submissionId,
        ], 404);
        return;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="reliableform-submission-' . $submissionId . '.pdf"');
    $size = filesize($real);
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    header('X-Content-Type-Options: nosniff');
    readfile($real);
    exit;
}

if ($status === 'failed') {
    render_page('pdf_wait', [
        'title' => 'PDF failed',
        'currentUser' => $user,
        'failed' => true,
        'error' => (string) ($job['error'] ?? 'The PDF worker reported a failure.'),
        'submissionId' => $submissionId,
    ]);
    return;
}

// pending / processing — friendly auto-refreshing wait page (meta refresh, 3s)
render_page('pdf_wait', [
    'title' => 'PDF is rendering',
    'currentUser' => $user,
    'failed' => false,
    'error' => null,
    'statusLabel' => $status,
    'submissionId' => $submissionId,
    'refresh' => 3,
]);
