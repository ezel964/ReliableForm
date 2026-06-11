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
 * Resolve a submitted answer for one form field from the submission data JSON
 * (same rendering rules as the pdf worker: checkbox joined ", ", missing "—").
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

/**
 * Permanently-bad job: log + dead-letter immediately, no retries.
 *
 * @param array<string, mixed> $job
 * @return string outcome for the job span/log ('dead')
 */
function webhook_dead_letter(array $job, string $reason): string
{
    worker_log('dead-lettering webhook job: ' . $reason);
    Metrics::increment('worker.webhook.failed');
    try {
        Queue::push(Queue::QUEUE_DEAD, $job + ['error' => $reason]);
    } catch (Throwable $e) {
        worker_log('failed pushing to dead queue: ' . $e->getMessage());
    }
    return 'dead';
}

/**
 * Build the delivery payload per the contract.
 *
 * @param array<string, mixed> $row delivery row joined with submission + form
 * @return array<string, mixed>
 */
function webhook_payload(array $row, int $attempt): array
{
    $fields = json_decode((string) $row['fields'], true);
    if (!is_array($fields)) {
        throw new RuntimeException('form fields JSON is invalid');
    }
    $data = json_decode((string) $row['data'], true);
    if (!is_array($data)) {
        $data = [];
    }

    $answers = [];
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $answers[] = [
            'id' => (string) ($field['id'] ?? ''),
            'label' => (string) ($field['label'] ?? ''),
            'type' => (string) ($field['type'] ?? ''),
            'value' => resolve_answer($field, $data),
        ];
    }

    return [
        'event' => 'submission.created',
        'form' => [
            'public_id' => (string) $row['public_id'],
            'title' => (string) $row['title'],
        ],
        'submission' => [
            'id' => (int) $row['submission_id'],
            'created_at' => (string) $row['created_at'],
            'answers' => $answers,
        ],
        'delivery' => [
            'id' => (int) $row['delivery_id'],
            'attempt' => $attempt,
        ],
    ];
}

/** scheme://host[:port] only — keep span attribute cardinality sane. */
function webhook_url_origin(string $url): string
{
    $p = parse_url($url);
    if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
        return $url; // unparseable — better than dropping the attribute
    }
    return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
}

/** Drill hazard note: webhook pointed at our own LB/web tier (still delivered). */
function webhook_self_loop_warn(string $url, int $deliveryId): void
{
    $p = parse_url($url);
    if (!is_array($p)) {
        return;
    }
    $host = strtolower((string) ($p['host'] ?? ''));
    if ($host !== 'localhost' && $host !== '127.0.0.1') {
        return;
    }
    $port = isset($p['port'])
        ? (int) $p['port']
        : ((($p['scheme'] ?? 'http') === 'https') ? 443 : 80);
    $own = [
        (int) Config::get('LB_PORT', '8080'),
        (int) Config::get('WEB1_PORT', '9001'),
        (int) Config::get('WEB2_PORT', '9002'),
    ];
    if (in_array($port, $own, true)) {
        worker_log(sprintf(
            'WARNING: delivery %d targets the stack\'s own web tier (%s) — self-loop drill hazard, delivering anyway',
            $deliveryId,
            $host . ':' . $port
        ));
    }
}

/**
 * POST the payload once. Emits one CLIENT span per attempt (fresh span id,
 * parented under the job's CONSUMER span = Trace::spanId()). On success
 * returns [http code, duration ms]; on failure updates attempts/response_code
 * on the row and throws so the standard retry contract takes over.
 *
 * @param array<string, mixed> $payload
 * @return array{0: int, 1: int} [response code, duration ms]
 */
function webhook_post(string $url, array $payload, int $deliveryId, int $attempt): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed for delivery ' . $deliveryId);
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT_MS => 2000,
        CURLOPT_TIMEOUT_MS => 5000,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: ReliableForm-Webhook/1.0',
            'X-ReliableForm-Delivery: ' . $deliveryId,
            'traceparent: ' . Trace::traceparent(),
        ],
    ]);

    $start = microtime(true);
    $result = curl_exec($ch);
    $end = microtime(true);
    heartbeat(instance_id()); // immediately after curl returns, per contract

    $curlErr = $result === false ? curl_error($ch) : '';
    $code = $result === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $okHttp = $code >= 200 && $code < 300;
    Otel::span(
        'webhook deliver',
        $start,
        $end,
        Otel::KIND_CLIENT,
        [
            'url.full' => webhook_url_origin($url),
            'http.response.status_code' => $code, // 0 when transport error
            'app.attempt' => $attempt,
            'app.delivery_id' => $deliveryId,
        ],
        !$okHttp,
        bin2hex(random_bytes(8)),  // fresh CLIENT span id
        Trace::spanId()            // parent = the job's CONSUMER span
    );

    if ($okHttp) {
        return [$code, (int) round(($end - $start) * 1000)];
    }

    // Record the failed attempt on the row before the retry path re-pushes.
    DB::run(
        'UPDATE webhook_deliveries SET attempts = attempts + 1, response_code = ? WHERE id = ?',
        [$code, $deliveryId]
    );
    if ($result === false) {
        throw new RuntimeException(sprintf('webhook POST failed: curl error: %s', $curlErr));
    }
    throw new RuntimeException(sprintf('webhook POST failed: HTTP %d', $code));
}

/**
 * @param array<string, mixed> $job
 * @return string outcome for the job span/log ('retried'|'dead')
 */
function webhook_handle_failure(array $job, int $submissionId, Throwable $e): string
{
    worker_log(sprintf(
        'webhook job for submission %d failed: %s: %s',
        $submissionId,
        get_class($e),
        $e->getMessage()
    ));
    Metrics::increment('worker.webhook.failed');

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
            // attempts already counted per-attempt in webhook_post().
            DB::run(
                "UPDATE webhook_deliveries SET status = 'failed', error = ?
                  WHERE submission_id = ?",
                [substr($e->getMessage(), 0, 500), $submissionId]
            );
        } catch (Throwable $dbErr) {
            // a failure updating the row must not crash the loop
            worker_log('failed marking webhook delivery failed: ' . $dbErr->getMessage());
        }
        return 'dead';
    }

    sleep_with_heartbeat(min(2 ** $attempt, 8), instance_id());
    try {
        Queue::push(Queue::QUEUE_WEBHOOK, $job);
    } catch (Throwable $pushErr) {
        worker_log('failed re-queueing webhook job: ' . $pushErr->getMessage());
    }
    return 'retried';
}

/**
 * @param array<string, mixed> $job
 * @return string outcome for the job span/log ('done'|'retried'|'dead'|'failed')
 */
function webhook_handle_job(array $job): string
{
    $start = microtime(true);

    $submissionId = $job['submission_id'] ?? null;
    if (!is_int($submissionId) && !(is_string($submissionId) && ctype_digit($submissionId))) {
        return webhook_dead_letter($job, 'invalid job shape: missing/non-int submission_id');
    }
    $submissionId = (int) $submissionId;
    $attempt = (int) ($job['attempt'] ?? 0);

    try {
        $row = DB::one(
            'SELECT wd.id AS delivery_id, wd.url, wd.status,
                    s.id AS submission_id, s.data, s.created_at,
                    f.public_id, f.title, f.fields
               FROM webhook_deliveries wd
               JOIN submissions s ON s.id = wd.submission_id
               JOIN forms f ON f.id = s.form_id
              WHERE wd.submission_id = ?',
            [$submissionId]
        );
        if ($row === null) {
            // Workers never create webhook_deliveries rows (web app owns them).
            return webhook_dead_letter($job, 'webhook_deliveries row missing for submission ' . $submissionId);
        }
        if ($row['status'] === 'delivered') {
            return 'done'; // idempotent: re-delivered job is a no-op
        }

        $deliveryId = (int) $row['delivery_id'];
        $url = (string) $row['url'];

        DB::run("UPDATE webhook_deliveries SET status = 'delivering' WHERE id = ?", [$deliveryId]);

        $payload = webhook_payload($row, $attempt);
        webhook_self_loop_warn($url, $deliveryId);
        [$code, $durationMs] = webhook_post($url, $payload, $deliveryId, $attempt);

        DB::run(
            "UPDATE webhook_deliveries
                SET status = 'delivered', response_code = ?, duration_ms = ?, error = NULL,
                    attempts = attempts + 1
              WHERE id = ?",
            [$code, $durationMs, $deliveryId]
        );

        Metrics::increment('worker.webhook.delivered');
        Metrics::timing('worker.webhook.job_ms', (microtime(true) - $start) * 1000);
        return 'done';
    } catch (Throwable $e) {
        return webhook_handle_failure($job, $submissionId, $e);
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

echo 'webhook worker started (instance ' . instance_id() . ')' . PHP_EOL;
Metrics::increment('worker.webhook.start');
heartbeat(instance_id());

$redisDown = false;

while ($running) {
    heartbeat(instance_id());

    try {
        $job = Queue::pop(Queue::QUEUE_WEBHOOK, 5);
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
    $outcome = webhook_handle_job($job);
    $jobEnd = microtime(true);

    $spanSubmissionId = (int) ($job['submission_id'] ?? 0);
    $spanAttempt = (int) ($job['attempt'] ?? 0);
    Otel::span(
        Queue::QUEUE_WEBHOOK . ' process',
        $jobStart,
        $jobEnd,
        Otel::KIND_CONSUMER,
        [
            'messaging.destination.name' => Queue::QUEUE_WEBHOOK,
            'app.submission_id' => $spanSubmissionId,
            'app.attempt' => $spanAttempt,
            'app.outcome' => $outcome,
        ],
        $outcome !== 'done'
    );
    AppLog::event('job', [
        'queue' => Queue::QUEUE_WEBHOOK,
        'submission_id' => $spanSubmissionId,
        'attempt' => $spanAttempt,
        'outcome' => $outcome,
        'dur_ms' => round(($jobEnd - $jobStart) * 1000.0, 1),
    ]);
    Otel::flush();
}

echo 'webhook worker stopping' . PHP_EOL;
exit(0);
