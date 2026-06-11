<?php

declare(strict_types=1);

/**
 * Driver-agnostic consume side of Queue — hides the BLPOP-vs-grab split so
 * each worker's per-job logic (handlers, retries, instrumentation) stays
 * untouched.
 *
 *   redis driver:   next() = Queue::pop() (BLPOP, behavior unchanged).
 *   gearman driver: next() registers the queue's function once per
 *                   connection (CAN_DO), then GRAB_JOB/PRE_SLEEP with the
 *                   same idle cadence as BLPOP.
 *
 * finish() WORK_COMPLETEs the grabbed gearman handle — ALWAYS, even when our
 * handler re-pushed a retry or dead-lettered the job: retries are OUR loop's
 * re-push semantics (a NEW gearman job under a fresh attempt-suffixed unique
 * id), so gearmand must never redeliver the original. WORK_FAIL is reserved
 * for transport-level weirdness via fail().
 *
 * gearmand outage handling mirrors the workers' Redis outage handling: log
 * once, keep polling at the same cadence, resume on reconnect. While down,
 * next() consumes the Redis fallback list — exactly where Queue::push()
 * parks jobs when gearmand is unreachable — so jobs keep flowing through the
 * outage. Idle gearman cycles also drain that list (non-blocking LPOP,
 * normally empty) so nothing pushed mid-outage is ever stranded.
 */
final class WorkerLoop
{
    private string $queue;
    private ?GearmanLite $gearman = null;
    private ?string $handle = null;
    private bool $gearmanDown = false;

    public function __construct(string $queue)
    {
        $this->queue = $queue;
    }

    /**
     * Block up to $timeout seconds for the next job (null = idle, caller
     * heartbeats and loops — the Queue::pop cadence contract). Throws
     * RedisException only when the redis path itself is down.
     *
     * @return array<string, mixed>|null
     */
    public function next(int $timeout = 5): ?array
    {
        $this->handle = null;
        if (Queue::driver() !== 'gearman') {
            return Queue::pop($this->queue, $timeout);
        }

        try {
            $grabbed = $this->session()->grab($timeout);
        } catch (GearmanException $e) {
            $this->dropSession();
            if (!$this->gearmanDown) {
                $this->gearmanDown = true; // log once per outage
                $this->log('gearmand unavailable (' . $e->getMessage() . ') — consuming the redis fallback list until it returns');
            }
            // Same cadence as the gearman idle path; jobs Queue::push()
            // parked in Redis during the outage keep flowing.
            return Queue::pop($this->queue, $timeout);
        }
        if ($this->gearmanDown) {
            $this->gearmanDown = false;
            $this->log('gearmand connection restored');
        }

        if ($grabbed !== null) {
            $this->handle = $grabbed['handle'];
            $job = json_decode($grabbed['payload'], true);
            if (!is_array($job)) {
                Metrics::increment('queue.malformed');
                $this->finish(); // ack-and-drop, like Queue::pop() does
                return null;
            }
            return $job;
        }

        // Idle gearman cycle: drain anything parked in the Redis fallback
        // list (pushed while gearmand was unreachable). Normally empty.
        return $this->popFallback();
    }

    /** Ack the current gearman job (no-op for redis / fallback jobs). */
    public function finish(): void
    {
        if ($this->handle === null) {
            return;
        }
        $handle = $this->handle;
        $this->handle = null;
        try {
            $this->session()->complete($handle);
        } catch (Throwable $e) {
            // gearmand vanished mid-job: the outcome is already recorded in
            // MySQL and every handler is idempotent, so a redelivery (or a
            // lost ack) is harmless. Reconnect on the next cycle.
            $this->dropSession();
            $this->log('WORK_COMPLETE failed (' . $e->getMessage() . ') — relying on job idempotency');
        }
    }

    /** WORK_FAIL the current job — transport-level weirdness only. */
    public function fail(): void
    {
        if ($this->handle === null) {
            return;
        }
        $handle = $this->handle;
        $this->handle = null;
        try {
            $this->session()->fail($handle);
        } catch (Throwable $e) {
            $this->dropSession();
            $this->log('WORK_FAIL failed (' . $e->getMessage() . ')');
        }
    }

    /** Connected worker session with our one function registered (CAN_DO). */
    private function session(): GearmanLite
    {
        if ($this->gearman === null || !$this->gearman->isConnected()) {
            $gm = $this->gearman ?? new GearmanLite();
            $gm->connect();
            $gm->register(Queue::gearmanFunction($this->queue));
            $this->gearman = $gm;
        }
        return $this->gearman;
    }

    private function dropSession(): void
    {
        if ($this->gearman !== null) {
            $this->gearman->close();
        }
        $this->gearman = null;
    }

    /**
     * Non-blocking LPOP of the Redis fallback list. Best-effort: Redis being
     * down must not stall gearman consumption (heartbeats already degrade).
     *
     * @return array<string, mixed>|null
     */
    private function popFallback(): ?array
    {
        try {
            $raw = RedisClient::instance()->command('LPOP', $this->queue);
        } catch (Throwable) {
            return null;
        }
        if (!is_string($raw)) {
            return null;
        }
        $job = json_decode($raw, true);
        if (!is_array($job)) {
            Metrics::increment('queue.malformed');
            return null;
        }
        return $job;
    }

    private function log(string $msg): void
    {
        $line = '[' . instance_id() . '] ' . $msg;
        error_log($line);
        if (defined('STDERR')) {
            fwrite(STDERR, $line . PHP_EOL);
        }
    }
}
