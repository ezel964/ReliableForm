<?php

declare(strict_types=1);

$user = Auth::requireLogin();
$submissionId = (int) $params['id'];

$submission = DB::one(
    'SELECT s.id, s.form_id, s.data, s.ip, s.user_agent, s.created_at,
            f.user_id AS owner_id, f.title AS form_title, f.fields AS form_fields, f.public_id
       FROM submissions s
       JOIN forms f ON f.id = s.form_id
      WHERE s.id = ?',
    [$submissionId]
);
if ($submission === null || (int) $submission['owner_id'] !== (int) $user['id']) {
    not_found('That submission does not exist.');
}

$fields = json_decode((string) $submission['form_fields'], true);
$data = json_decode((string) $submission['data'], true);

$pdfJob = DB::one(
    'SELECT status, file_path, error, attempts, updated_at FROM pdf_jobs WHERE submission_id = ?',
    [$submissionId]
);
// One row per kind since the autoresponder landed — pick each explicitly.
$emailRow = DB::one(
    "SELECT status, to_email, error, updated_at FROM emails WHERE submission_id = ? AND kind = 'notification'",
    [$submissionId]
);
$autoresponderRow = DB::one(
    "SELECT status, to_email, error, updated_at FROM emails WHERE submission_id = ? AND kind = 'autoresponder'",
    [$submissionId]
);
// Webhook delivery (NULL when the form had no webhook at submit time) — shown
// as a job row next to PDF/email on the detail page.
$webhookRow = DB::one(
    'SELECT status, response_code, attempts, error, updated_at FROM webhook_deliveries WHERE submission_id = ?',
    [$submissionId]
);

render_page('submission_detail', [
    'title' => 'Submission #' . $submissionId,
    'currentUser' => $user,
    'submission' => $submission,
    'fields' => is_array($fields) ? $fields : [],
    'data' => is_array($data) ? $data : [],
    'pdfJob' => $pdfJob,
    'emailRow' => $emailRow,
    'autoresponderRow' => $autoresponderRow,
    'webhookRow' => $webhookRow,
]);
