<?php

declare(strict_types=1);

/**
 * GET /forms/{id}/export.csv — stream every submission of an owned form.
 *
 * Columns: submission_id, submitted_at_utc, then one column per field
 * (label as header, in field order). Checkbox arrays joined "; ", file
 * answers are the stored path string, missing answers are empty strings.
 * RFC-4180 quoting + spreadsheet formula-injection guard.
 */

require_once RF_ROOT . '/apps/web/src/csv.php'; // csv_cell() / csv_answer()

$user = Auth::requireLogin();
$form = owned_form($user, (int) $params['id']); // 404 view when missing/not owned

$fields = json_decode((string) $form['fields'], true);
if (!is_array($fields)) {
    $fields = [];
}

Metrics::increment('web.export');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $form['public_id'] . '-submissions.csv"');

$out = fopen('php://output', 'wb');
if ($out === false) {
    exit; // nothing sensible left to do; headers may already be out
}

$headerRow = ['submission_id', 'submitted_at_utc'];
foreach ($fields as $field) {
    $headerRow[] = (string) ($field['label'] ?? '');
}
fwrite($out, implode(',', array_map('csv_cell', $headerRow)) . "\r\n");

// Single query, streamed row by row (fetch(), not fetchAll()) so big forms
// don't buffer every submission in memory.
$stmt = DB::run(
    'SELECT id, created_at, data FROM submissions WHERE form_id = ? ORDER BY id',
    [(int) $form['id']]
);
while (($row = $stmt->fetch()) !== false) {
    $data = json_decode((string) $row['data'], true);
    if (!is_array($data)) {
        $data = [];
    }
    $cells = [(string) (int) $row['id'], (string) $row['created_at']];
    foreach ($fields as $field) {
        $cells[] = csv_answer($data[(string) ($field['id'] ?? '')] ?? '');
    }
    fwrite($out, implode(',', array_map('csv_cell', $cells)) . "\r\n");
}

fclose($out);
exit;
