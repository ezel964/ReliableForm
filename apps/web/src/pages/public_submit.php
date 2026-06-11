<?php

declare(strict_types=1);

$publicId = (string) $params['public_id'];

$form = load_public_form($publicId);
if ($form === null) {
    not_found('This form is not available.');
}

// Honeypot: real visitors never see/fill the "website" input. Pretend success.
if (post_str('website') !== '') {
    Metrics::increment('web.submission.honeypot');
    redirect('/f/' . $publicId . '/thanks');
}

// Unpublished or at its submission limit ⇒ 410 + closed page, before the
// rate limiter and validation ever run (a closed form does no work for you).
$closedReason = form_closed_reason($form);
if ($closedReason !== null) {
    Metrics::increment('web.submission.closed');
    render_page('form_closed', [
        'title' => (string) $form['title'],
        'form' => $form,
        'reason' => $closedReason,
    ], 410);
    return;
}

// Advisory per-IP submission rate limit (XFF-keyed; see ARCHITECTURE.md).
// Redis trouble ⇒ fail open: the limiter must never block a real submission.
$rateLimit = (int) Config::get('RATE_LIMIT_SUBMIT_PER_MIN', '30');
if ($rateLimit > 0) {
    try {
        $redis = RedisClient::instance();
        $rateKey = 'ratelimit:submit:' . $publicId . ':' . request_ip();
        $rateCount = $redis->incr($rateKey);
        if ($rateCount === 1) {
            $redis->expire($rateKey, 60);
        }
        if ($rateCount > $rateLimit) {
            Metrics::increment('web.submission.ratelimited');
            header('Retry-After: 30');
            render_page('rate_limited', [
                'title' => 'Slow down a moment',
                'form' => $form,
                'publicId' => $publicId,
            ], 429);
            return;
        }
    } catch (RedisException) {
        // fail open
    }
}

$fields = json_decode((string) $form['fields'], true);
if (!is_array($fields)) {
    $fields = [];
}

$answers = $_POST['answers'] ?? [];
if (!is_array($answers)) {
    $answers = [];
}
$fileGroup = $_FILES['files'] ?? [];
if (!is_array($fileGroup)) {
    $fileGroup = [];
}

$check = validate_submission($fields, $answers, $fileGroup);
if (!$check['ok']) {
    render_page('public_form', [
        'title' => (string) $form['title'],
        'form' => $form,
        'fields' => $fields,
        'errors' => $check['errors'],
        'old' => $check['data'],
        'publicId' => $publicId,
    ]);
    return;
}

// ALL fields validated — only now are uploads moved out of PHP's tmp dir.
// Stored name is NEVER the client filename: <utcstamp>-<rand8>.<ext>, under
// storage/uploads/<public_id>/. Files are write-only (never served back).
if ($check['uploads'] !== []) {
    $uploadDir = RF_ROOT . '/storage/uploads/' . $publicId;
    $storedPaths = [];
    $storeErrors = [];
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        foreach ($check['uploads'] as $fid => $upload) {
            $storeErrors[$fid] = 'We could not store your file — please try again.';
        }
    } else {
        foreach ($check['uploads'] as $fid => $upload) {
            $rand = '';
            $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
            for ($i = 0; $i < 8; $i++) {
                $rand .= $chars[random_int(0, 35)];
            }
            $storedName = gmdate('YmdHis') . '-' . $rand . '.' . $upload['ext'];
            if (!@move_uploaded_file($upload['tmp_name'], $uploadDir . '/' . $storedName)) {
                $storeErrors[$fid] = 'We could not store your file — please try again.';
                continue;
            }
            $storedPaths[$fid] = $uploadDir . '/' . $storedName;
            $check['data'][$fid] = 'storage/uploads/' . $publicId . '/' . $storedName;
        }
    }
    if ($storeErrors !== []) {
        // No partial submission: drop anything this request already stored.
        foreach ($storedPaths as $path) {
            @unlink($path);
        }
        Metrics::increment('web.submission.upload_store_failed');
        render_page('public_form', [
            'title' => (string) $form['title'],
            'form' => $form,
            'fields' => $fields,
            'errors' => $storeErrors,
            'old' => $check['data'],
            'publicId' => $publicId,
        ]);
        return;
    }
}

$dataJson = json_encode($check['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Tolerate a cached pre-iteration-2 form row (no webhook_url key) for ≤60s.
$webhookUrl = trim((string) ($form['webhook_url'] ?? ''));

// Autoresponder: only when enabled AND the first email-type field carries a
// valid address (autoresponder_recipient returns null otherwise).
$autoresponderTo = null;
if ((int) ($form['autoresponder_enabled'] ?? 0) === 1) {
    $autoresponderTo = autoresponder_recipient($fields, $check['data']);
}

// ONE transaction: submission + pending pdf_jobs row + pending emails row.
// Workers only ever UPDATE these rows — creating them here is the contract.
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
        [$submissionId, (string) $form['owner_email']]
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
    throw $e; // bootstrap's handler logs it and shows the friendly 500 page
}

Metrics::increment('web.submission');

// Post-commit Redis work (stats + queue pushes) is best-effort: Redis being
// down must not 500 a visitor whose submission is already safely stored.
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
        '[%s] post-commit Redis work failed for submission %d: %s',
        instance_id(),
        $submissionId,
        $e->getMessage()
    ));
    Metrics::increment('web.submission.redis_degraded');
    try {
        flash('info', 'Your response was saved, but background processing is briefly delayed.');
    } catch (Throwable) {
        // session backend is Redis too; nothing more we can do
    }
}

redirect('/f/' . $publicId . '/thanks');
