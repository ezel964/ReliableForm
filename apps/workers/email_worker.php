<?php

declare(strict_types=1);

require __DIR__ . '/../../lib/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function worker_log(string $msg): void
{
    $line = '[' . instance_id() . '] ' . $msg;
    error_log($line);
    fwrite(STDERR, $line . PHP_EOL);
}

/**
 * Resolve a submitted answer for one form field from the submission data JSON.
 * Same rules as the PDF worker: checkbox arrays joined, rating "n / max",
 * missing answers shown as an em dash.
 *
 * @param array<string, mixed> $field
 * @param array<string, mixed> $data
 */
function resolve_answer(array $field, array $data): string
{
    $raw = $data[(string) ($field['id'] ?? '')] ?? null;
    if ($raw === null || $raw === '' || $raw === []) {
        return '—';
    }
    $toStr = static fn ($v): string => is_scalar($v) ? (string) $v : '?';
    $type = (string) ($field['type'] ?? '');
    if ($type === 'rating') {
        return $toStr($raw) . ' / ' . (int) ($field['max'] ?? 5);
    }
    if (is_array($raw)) { // checkbox submits string[]
        return implode(', ', array_map($toStr, $raw));
    }
    return $toStr($raw);
}

/** Write via a temp file in the same directory so rename() is an atomic move. */
function atomic_write(string $path, string $bytes): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('cannot create directory ' . $dir);
    }
    $tmp = $dir . '/.tmp-' . basename($path) . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $bytes) !== strlen($bytes)) {
        @unlink($tmp);
        throw new RuntimeException('failed writing temp file ' . $tmp);
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('failed renaming temp file to ' . $path);
    }
}

/**
 * Permanently-bad job: log + dead-letter immediately, no retries.
 *
 * @param array<string, mixed> $job
 * @return string outcome for the job span/log ('dead')
 */
function email_dead_letter(array $job, string $reason): string
{
    worker_log('dead-lettering email job: ' . $reason);
    Metrics::increment('worker.email.failed');
    try {
        Queue::push(Queue::QUEUE_DEAD, $job + ['error' => $reason]);
    } catch (Throwable $e) {
        worker_log('failed pushing to dead queue: ' . $e->getMessage());
    }
    return 'dead';
}

/**
 * Compose an RFC-822-style plain-text message. Never calls mail().
 *
 * @param array<string, mixed> $row submission joined with its form
 */
function eml_build(array $row, string $toEmail): string
{
    $fields = json_decode((string) $row['fields'], true);
    if (!is_array($fields)) {
        throw new RuntimeException('form fields JSON is invalid');
    }
    $data = json_decode((string) $row['data'], true);
    if (!is_array($data)) {
        $data = [];
    }

    $title = (string) $row['title'];
    $submissionId = (int) $row['id'];
    $appUrl = rtrim((string) Config::get('APP_URL', 'http://localhost:8080'), '/');

    $headers = [
        'From: ReliableForm <noreply@reliableform.dev>',
        'To: ' . $toEmail,
        'Subject: New submission on "' . $title . '"',
        'Date: ' . gmdate(DATE_RFC2822),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'X-ReliableForm-Submission: ' . $submissionId,
    ];

    $lines = [
        'Hi,',
        '',
        sprintf('You have a new submission on "%s" (submission #%d).', $title, $submissionId),
        '',
    ];
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $lines[] = (string) ($field['label'] ?? '') . ': ' . resolve_answer($field, $data);
    }
    $lines[] = '';
    $lines[] = 'View it in ReliableForm: ' . $appUrl . '/submissions/' . $submissionId;
    $lines[] = '';
    $lines[] = '-- ';
    $lines[] = 'ReliableForm';

    return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $lines) . "\r\n";
}

/**
 * Compose the autoresponder: addressed to the submitter, thanking them and
 * echoing their own answers. The notification message is eml_build() above —
 * its output stays byte-for-byte what it always was.
 *
 * @param array<string, mixed> $row submission joined with its form
 */
function eml_build_autoresponder(array $row, string $toEmail): string
{
    $fields = json_decode((string) $row['fields'], true);
    if (!is_array($fields)) {
        throw new RuntimeException('form fields JSON is invalid');
    }
    $data = json_decode((string) $row['data'], true);
    if (!is_array($data)) {
        $data = [];
    }

    $title = (string) $row['title'];
    $submissionId = (int) $row['id'];

    $headers = [
        'From: ReliableForm <noreply@reliableform.dev>',
        'To: ' . $toEmail,
        'Subject: Thanks for your submission — "' . $title . '"',
        'Date: ' . gmdate(DATE_RFC2822),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        'X-ReliableForm-Submission: ' . $submissionId,
    ];

    $lines = [
        'Hi,',
        '',
        sprintf('Thanks for your submission to "%s" — here is a copy of your answers:', $title),
        '',
    ];
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $lines[] = (string) ($field['label'] ?? '') . ': ' . resolve_answer($field, $data);
    }
    $lines[] = '';
    $lines[] = '-- ';
    $lines[] = 'ReliableForm';

    return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $lines) . "\r\n";
}

/**
 * @param array<string, mixed> $job
 * @return string outcome for the job span/log ('retried'|'dead')
 */
function email_handle_failure(array $job, int $submissionId, string $kind, Throwable $e): string
{
    worker_log(sprintf(
        'email job (%s) for submission %d failed: %s: %s',
        $kind,
        $submissionId,
        get_class($e),
        $e->getMessage()
    ));
    Metrics::increment('worker.email.failed');

    if ($e instanceof PDOException) {
        DB::reset(); // drop the cached connection so the next attempt reconnects
    }

    $attempt = (int) ($job['attempt'] ?? 0) + 1;
    $job['attempt'] = $attempt;

    if ($attempt >= 3) {
        try {
            Queue::push(Queue::QUEUE_DEAD, $job + ['error' => $e->getMessage()]);
        } catch (Throwable $pushErr) {
            worker_log('failed pushing to dead queue: ' . $pushErr->getMessage());
        }
        try {
            DB::run(
                "UPDATE emails SET status = 'failed', error = ? WHERE submission_id = ? AND kind = ?",
                [substr($e->getMessage(), 0, 500), $submissionId, $kind]
            );
        } catch (Throwable $dbErr) {
            // a failure updating the row must not crash the loop
            worker_log('failed marking email failed: ' . $dbErr->getMessage());
        }
        return 'dead';
    }

    sleep_with_heartbeat(min(2 ** $attempt, 8), instance_id());
    try {
        Queue::push(Queue::QUEUE_EMAIL, $job);
    } catch (Throwable $pushErr) {
        worker_log('failed re-queueing email job: ' . $pushErr->getMessage());
    }
    return 'retried';
}

/**
 * @param array<string, mixed> $job
 * @return string outcome for the job span/log ('done'|'retried'|'dead'|'failed')
 */
function email_handle_job(array $job): string
{
    $start = microtime(true);

    $submissionId = $job['submission_id'] ?? null;
    if (!is_int($submissionId) && !(is_string($submissionId) && ctype_digit($submissionId))) {
        return email_dead_letter($job, 'invalid job shape: missing/non-int submission_id');
    }
    $submissionId = (int) $submissionId;

    // Producers older than the autoresponder feature send no kind ⇒ notification.
    $kind = $job['kind'] ?? 'notification';
    if (!in_array($kind, ['notification', 'autoresponder'], true)) {
        return email_dead_letter($job, 'invalid job shape: unknown kind ' . json_encode($kind));
    }

    try {
        // The web app owns the row (to_email pre-set); workers never INSERT it.
        // One row per (submission, kind) — UNIQUE(submission_id, kind).
        $emailRow = DB::one(
            'SELECT to_email, status FROM emails WHERE submission_id = ? AND kind = ?',
            [$submissionId, $kind]
        );
        if ($emailRow === null) {
            return email_dead_letter($job, $kind . ' emails row missing for submission ' . $submissionId);
        }
        if ($emailRow['status'] === 'sent') {
            return 'done'; // idempotent: re-delivered job is a no-op
        }

        $row = DB::one(
            'SELECT s.id, s.data, s.created_at, f.title, f.fields
               FROM submissions s
               JOIN forms f ON f.id = s.form_id
              WHERE s.id = ?',
            [$submissionId]
        );
        if ($row === null) {
            return email_dead_letter($job, 'submission ' . $submissionId . ' not found');
        }

        if ($kind === 'autoresponder') {
            $relPath = 'storage/mail/submission-' . $submissionId . '-autoresponder.eml';
            $eml = eml_build_autoresponder($row, (string) $emailRow['to_email']);
        } else {
            $relPath = 'storage/mail/submission-' . $submissionId . '.eml';
            $eml = eml_build($row, (string) $emailRow['to_email']);
        }
        atomic_write(RF_ROOT . '/' . $relPath, $eml);

        DB::run(
            "UPDATE emails SET status = 'sent', file_path = ?, error = NULL WHERE submission_id = ? AND kind = ?",
            [$relPath, $submissionId, $kind]
        );

        Metrics::increment('worker.email.sent');
        Metrics::timing('worker.email.job_ms', (microtime(true) - $start) * 1000);
        return 'done';
    } catch (Throwable $e) {
        return email_handle_failure($job, $submissionId, $kind, $e);
    }
}

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$running): void {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

echo 'email worker started (instance ' . instance_id() . ')' . PHP_EOL;
Metrics::increment('worker.email.start');
heartbeat(instance_id());

$redisDown = false;

while ($running) {
    heartbeat(instance_id());

    try {
        $job = Queue::pop(Queue::QUEUE_EMAIL, 5);
    } catch (RedisException $e) {
        if (!$running) {
            break; // signal interrupted the blocking pop during shutdown
        }
        if (!$redisDown) {
            $redisDown = true; // log once per outage, not every retry
            worker_log('redis unavailable, retrying until it returns: ' . $e->getMessage());
        }
        sleep(3);
        continue;
    }
    if ($redisDown) {
        $redisDown = false;
        worker_log('redis connection restored');
    }

    if ($job === null) {
        continue;
    }

    // Resume the producer's trace, time the job, and emit exactly one
    // CONSUMER span + one structured log line per processed job. flush()
    // every iteration — this process never exits, a shutdown hook won't fire.
    Trace::startFromJob($job);
    $jobStart = microtime(true);
    $outcome = email_handle_job($job);
    $jobEnd = microtime(true);

    $spanSubmissionId = (int) ($job['submission_id'] ?? 0);
    $spanAttempt = (int) ($job['attempt'] ?? 0);
    Otel::span(
        Queue::QUEUE_EMAIL . ' process',
        $jobStart,
        $jobEnd,
        Otel::KIND_CONSUMER,
        [
            'messaging.destination.name' => Queue::QUEUE_EMAIL,
            'app.submission_id' => $spanSubmissionId,
            'app.attempt' => $spanAttempt,
            'app.outcome' => $outcome,
        ],
        $outcome !== 'done'
    );
    AppLog::event('job', [
        'queue' => Queue::QUEUE_EMAIL,
        'submission_id' => $spanSubmissionId,
        'attempt' => $spanAttempt,
        'outcome' => $outcome,
        'dur_ms' => round(($jobEnd - $jobStart) * 1000.0, 1),
    ]);
    Otel::flush();
}

echo 'email worker stopping' . PHP_EOL;
exit(0);
