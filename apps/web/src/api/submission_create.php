<?php

declare(strict_types=1);

/**
 * POST /v1/forms/{public_id}/submissions — body {"answers":{"<field_id>":value}}.
 *
 * Mirrors apps/web/src/pages/public_submit.php exactly: ONE transaction
 * inserting the submission + pending pdf_jobs row + pending emails row
 * (+ pending webhook_deliveries row when the form has a webhook_url), then
 * post-commit stats INCRs and queue pushes — Redis failures there must not
 * fail the call, the submission is already stored (still 201).
 *
 * @var array{id: int, email: string} $apiUser
 * @var array<string, string> $params
 */

$form = api_owned_form($apiUser, (string) $params['public_id']);

// Content-Type must be application/json (parameters like charset are fine).
$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
$mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
if ($mediaType !== 'application/json') {
    api_error('unsupported_media_type', 'Content-Type must be application/json.', 415);
}

$rawBody = (string) file_get_contents('php://input');
$body = json_decode($rawBody, true);
if (!is_array($body)) {
    api_error('validation_failed', 'Body must be a valid JSON object.', 422);
}

$answers = $body['answers'] ?? [];
if (!is_array($answers)) {
    $answers = [];
}

$fields = api_decode_fields((string) $form['fields']);

// File fields are skipped by the API (JSON has no multipart): a required file
// field can never be satisfied => 422 for it; optional ones are ignored and
// any answer sent for them is dropped (they are excluded from validation, so
// they never reach the stored data).
$fileErrors = [];
$validatable = [];
foreach ($fields as $field) {
    if (($field['type'] ?? '') === 'file') {
        if (!empty($field['required'])) {
            $fileErrors[(string) ($field['id'] ?? '')] =
                'File uploads are not supported via the API. Use the public form for this required field.';
        }
        continue;
    }
    $validatable[] = $field;
}

// JSON clients naturally send numbers for number/rating fields; the shared
// validator (validation.php) expects the strings $_POST would carry. Coerce
// numeric scalars to strings — validation semantics stay identical.
$normalized = [];
foreach ($answers as $key => $value) {
    if (is_int($value) || is_float($value)) {
        $value = (string) $value;
    } elseif (is_array($value)) {
        $value = array_map(
            static fn ($v) => (is_int($v) || is_float($v)) ? (string) $v : $v,
            $value
        );
    }
    $normalized[(string) $key] = $value;
}

$check = validate_submission($validatable, $normalized);
$fieldErrors = $check['errors'] + $fileErrors; // ids are disjoint by construction
if ($fieldErrors !== []) {
    api_error('validation_failed', 'Validation failed for one or more fields.', 422, $fieldErrors);
}

$dataJson = json_encode($check['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$webhookUrl = trim((string) ($form['webhook_url'] ?? ''));

// Autoresponder — identical to the web submit path: only when enabled AND
// the first email-type field carries a valid address.
$autoresponderTo = null;
if ((int) ($form['autoresponder_enabled'] ?? 0) === 1) {
    $autoresponderTo = autoresponder_recipient($fields, $check['data']);
}

// ONE transaction: submission + pending pdf_jobs row + pending emails row
// (+ pending webhook_deliveries row when a webhook is configured). Workers
// only ever UPDATE these rows — creating them here is the contract. The form
// is owner-scoped, so the owner's email is the authenticated user's email.
$pdo = DB::pdo();
$pdo->beginTransaction();
try {
    $submissionId = DB::insert(
        'INSERT INTO submissions (form_id, data, ip, user_agent) VALUES (?, ?, ?, ?)',
        [
            (int) $form['id'],
            $dataJson,
            request_ip(),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]
    );
    DB::run("INSERT INTO pdf_jobs (submission_id, status) VALUES (?, 'pending')", [$submissionId]);
    DB::run(
        "INSERT INTO emails (submission_id, kind, to_email, status) VALUES (?, 'notification', ?, 'pending')",
        [$submissionId, $apiUser['email']]
    );
    if ($autoresponderTo !== null) {
        DB::run(
            "INSERT INTO emails (submission_id, kind, to_email, status) VALUES (?, 'autoresponder', ?, 'pending')",
            [$submissionId, $autoresponderTo]
        );
    }
    if ($webhookUrl !== '') {
        DB::run(
            "INSERT INTO webhook_deliveries (submission_id, url, status) VALUES (?, ?, 'pending')",
            [$submissionId, $webhookUrl]
        );
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e; // bootstrap's RF_API branch answers with the JSON 500 envelope
}

Metrics::increment('api.submission');

// Post-commit Redis work (stats + queue pushes) is best-effort: the
// submission is safely stored, so Redis being down must not fail the call.
try {
    $redis = RedisClient::instance();
    $redis->incr('stats:submissions:total');
    $hourKey = 'stats:submissions:' . gmdate('YmdH'); // UTC bucket per contract
    if ($redis->incr($hourKey) === 1) {
        $redis->expire($hourKey, 90000);
    }
    $enqueuedAt = gmdate('c');
    Queue::push(Queue::QUEUE_PDF, [
        'type' => 'pdf', 'submission_id' => $submissionId, 'attempt' => 0, 'enqueued_at' => $enqueuedAt,
    ]);
    Queue::push(Queue::QUEUE_EMAIL, [
        'type' => 'email', 'submission_id' => $submissionId, 'attempt' => 0, 'enqueued_at' => $enqueuedAt,
    ]);
    if ($autoresponderTo !== null) {
        Queue::push(Queue::QUEUE_EMAIL, [
            'type' => 'email', 'submission_id' => $submissionId, 'attempt' => 0, 'enqueued_at' => $enqueuedAt,
            'kind' => 'autoresponder',
        ]);
    }
    if ($webhookUrl !== '') {
        Queue::push(Queue::QUEUE_WEBHOOK, [
            'type' => 'webhook', 'submission_id' => $submissionId, 'attempt' => 0, 'enqueued_at' => $enqueuedAt,
        ]);
    }
} catch (RedisException $e) {
    error_log(sprintf(
        '[%s] post-commit Redis work failed for API submission %d: %s',
        instance_id(),
        $submissionId,
        $e->getMessage()
    ));
    Metrics::increment('api.submission.redis_degraded');
}

api_json(['ok' => true, 'submission' => ['id' => $submissionId]], 201);
