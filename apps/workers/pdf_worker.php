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

function submitted_at_utc(string $createdAt): string
{
    $ts = strtotime($createdAt); // bootstrap pins PHP to UTC, matching MySQL TIMESTAMP text
    return ($ts === false ? $createdAt : gmdate('Y-m-d H:i:s', $ts)) . ' UTC';
}

/**
 * Permanently-bad job: log + dead-letter immediately, no retries.
 *
 * @param array<string, mixed> $job
 * @return string outcome for the job span/log ('dead')
 */
function pdf_dead_letter(array $job, string $reason): string
{
    worker_log('dead-lettering pdf job: ' . $reason);
    Metrics::increment('worker.pdf.failed');
    try {
        Queue::push(Queue::QUEUE_DEAD, $job + ['error' => $reason]);
    } catch (Throwable $e) {
        worker_log('failed pushing to dead queue: ' . $e->getMessage());
    }
    return 'dead';
}

/** @param array<string, mixed> $row submission joined with its form */
function pdf_build(array $row): string
{
    $fields = json_decode((string) $row['fields'], true);
    if (!is_array($fields)) {
        throw new RuntimeException('form fields JSON is invalid');
    }
    $data = json_decode((string) $row['data'], true);
    if (!is_array($data)) {
        $data = [];
    }

    $pdf = new Pdf();
    $pdf->addPage();
    $pdf->heading((string) $row['title']);
    $pdf->text('Submission #' . (int) $row['id']);
    $pdf->text('Submitted at ' . submitted_at_utc((string) $row['created_at']));
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $pdf->keyValue((string) ($field['label'] ?? ''), resolve_answer($field, $data));
    }
    return $pdf->render();
}

/**
 * @param array<string, mixed> $job
 * @return string outcome for the job span/log ('retried'|'dead')
 */
function pdf_handle_failure(array $job, int $submissionId, Throwable $e): string
{
    worker_log(sprintf(
        'pdf job for submission %d failed: %s: %s',
        $submissionId,
        get_class($e),
        $e->getMessage()
    ));
    Metrics::increment('worker.pdf.failed');

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
                "UPDATE pdf_jobs SET status = 'failed', error = ?, attempts = attempts + 1
                  WHERE submission_id = ?",
                [substr($e->getMessage(), 0, 500), $submissionId]
            );
        } catch (Throwable $dbErr) {
            // a failure updating the row must not crash the loop
            worker_log('failed marking pdf job failed: ' . $dbErr->getMessage());
        }
        return 'dead';
    }

    sleep_with_heartbeat(min(2 ** $attempt, 8), instance_id());
    try {
        Queue::push(Queue::QUEUE_PDF, $job);
    } catch (Throwable $pushErr) {
        worker_log('failed re-queueing pdf job: ' . $pushErr->getMessage());
    }
    return 'retried';
}

/**
 * @param array<string, mixed> $job
 * @return string outcome for the job span/log ('done'|'retried'|'dead'|'failed')
 */
function pdf_handle_job(array $job): string
{
    $start = microtime(true);

    $submissionId = $job['submission_id'] ?? null;
    if (!is_int($submissionId) && !(is_string($submissionId) && ctype_digit($submissionId))) {
        return pdf_dead_letter($job, 'invalid job shape: missing/non-int submission_id');
    }
    $submissionId = (int) $submissionId;

    try {
        $jobRow = DB::one('SELECT status FROM pdf_jobs WHERE submission_id = ?', [$submissionId]);
        if ($jobRow === null) {
            // Workers never create pdf_jobs rows (web app owns them).
            return pdf_dead_letter($job, 'pdf_jobs row missing for submission ' . $submissionId);
        }
        if ($jobRow['status'] === 'done') {
            return 'done'; // idempotent: re-delivered job is a no-op
        }

        $row = DB::one(
            'SELECT s.id, s.data, s.created_at, f.title, f.fields, u.email AS owner_email
               FROM submissions s
               JOIN forms f ON f.id = s.form_id
               JOIN users u ON u.id = f.user_id
              WHERE s.id = ?',
            [$submissionId]
        );
        if ($row === null) {
            return pdf_dead_letter($job, 'submission ' . $submissionId . ' not found');
        }

        DB::run("UPDATE pdf_jobs SET status = 'processing' WHERE submission_id = ?", [$submissionId]);

        $relPath = 'storage/pdfs/submission-' . $submissionId . '.pdf';
        atomic_write(RF_ROOT . '/' . $relPath, pdf_build($row));

        DB::run(
            "UPDATE pdf_jobs SET status = 'done', file_path = ?, error = NULL, attempts = attempts + 1
              WHERE submission_id = ?",
            [$relPath, $submissionId]
        );

        Metrics::increment('worker.pdf.done');
        Metrics::timing('worker.pdf.job_ms', (microtime(true) - $start) * 1000);
        return 'done';
    } catch (Throwable $e) {
        return pdf_handle_failure($job, $submissionId, $e);
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

echo 'pdf worker started (instance ' . instance_id() . ')' . PHP_EOL;
Metrics::increment('worker.pdf.start');
heartbeat(instance_id());

$redisDown = false;

while ($running) {
    heartbeat(instance_id());

    try {
        $job = Queue::pop(Queue::QUEUE_PDF, 5);
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
    $outcome = pdf_handle_job($job);
    $jobEnd = microtime(true);

    $spanSubmissionId = (int) ($job['submission_id'] ?? 0);
    $spanAttempt = (int) ($job['attempt'] ?? 0);
    Otel::span(
        Queue::QUEUE_PDF . ' process',
        $jobStart,
        $jobEnd,
        Otel::KIND_CONSUMER,
        [
            'messaging.destination.name' => Queue::QUEUE_PDF,
            'app.submission_id' => $spanSubmissionId,
            'app.attempt' => $spanAttempt,
            'app.outcome' => $outcome,
        ],
        $outcome !== 'done'
    );
    AppLog::event('job', [
        'queue' => Queue::QUEUE_PDF,
        'submission_id' => $spanSubmissionId,
        'attempt' => $spanAttempt,
        'outcome' => $outcome,
        'dur_ms' => round(($jobEnd - $jobStart) * 1000.0, 1),
    ]);
    Otel::flush();
}

echo 'pdf worker stopping' . PHP_EOL;
exit(0);
