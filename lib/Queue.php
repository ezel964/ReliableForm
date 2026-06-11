<?php

declare(strict_types=1);

/**
 * Queue facade over two drivers — public API, metric names and trace
 * embedding are UNCHANGED from the redis-only version.
 *
 *   redis   — RPUSH/BLPOP on the queue:* lists (the original behavior).
 *   gearman — SUBMIT_JOB_BG to gearmand with production-style unique ids
 *             (queue:pdf→rf_pdf, queue:email→rf_email, queue:webhook→rf_webhook).
 *
 * Driver resolution: launch.sh resolves QUEUE_DRIVER=auto ONCE per stack run
 * and exports gearman|redis to every service, so PHP normally never sees the
 * literal "auto". If it does (ad-hoc CLI, unit tests), gearman wins when a
 * 200ms TCP connect to GEARMAN_HOST:GEARMAN_PORT succeeds — cached per
 * process.
 *
 * queue:dead stays a Redis list under BOTH drivers (one inspectable
 * dead-letter surface: redis-cli LRANGE queue:dead 0 -1). pop() is likewise
 * redis-only — the gearman consume side lives in WorkerLoop.
 */
final class Queue
{
    public const QUEUE_PDF = 'queue:pdf';
    public const QUEUE_EMAIL = 'queue:email';
    public const QUEUE_WEBHOOK = 'queue:webhook';
    public const QUEUE_DEAD = 'queue:dead';

    /** Redis list name → gearman function name. */
    private const GEARMAN_FUNCTIONS = [
        self::QUEUE_PDF => 'rf_pdf',
        self::QUEUE_EMAIL => 'rf_email',
        self::QUEUE_WEBHOOK => 'rf_webhook',
    ];

    private static ?string $driver = null;
    private static ?GearmanLite $client = null;

    /** Resolved driver for this process: 'gearman' | 'redis'. */
    public static function driver(): string
    {
        if (self::$driver === null) {
            $cfg = strtolower((string) Config::get('QUEUE_DRIVER', 'auto'));
            if ($cfg === 'gearman' || $cfg === 'redis') {
                self::$driver = $cfg;
            } else {
                // 'auto' (or junk): launch.sh should have resolved this for
                // us — fall back to a 200ms reachability probe, once.
                self::$driver = self::gearmandReachable() ? 'gearman' : 'redis';
            }
        }
        return self::$driver;
    }

    public static function gearmanFunction(string $queue): string
    {
        return self::GEARMAN_FUNCTIONS[$queue]
            ?? ('rf_' . str_replace([':', '/'], '_', preg_replace('/^queue:/', '', $queue) ?? $queue));
    }

    /** @param array<string, mixed> $job */
    public static function push(string $queue, array $job): void
    {
        // Carry the trace across the async boundary; workers resume it via
        // Trace::startFromJob(). Extra key is ignored by older consumers.
        if (!isset($job['trace'])) {
            $job['trace'] = Trace::context();
        }
        $payload = json_encode($job, JSON_THROW_ON_ERROR);

        // Dead letters never ride gearman; unknown queue names keep the
        // legacy redis path too.
        if (self::driver() === 'gearman' && isset(self::GEARMAN_FUNCTIONS[$queue])) {
            self::pushGearman($queue, $job, $payload);
        } else {
            RedisClient::instance()->rpush($queue, $payload);
        }
        Metrics::increment('queue.push.' . str_replace(':', '_', $queue));
    }

    /** @return array<string, mixed>|null */
    public static function pop(string $queue, int $timeout = 5): ?array
    {
        $res = RedisClient::instance()->blpop($queue, $timeout);
        if ($res === null) {
            return null;
        }
        $job = json_decode($res[1], true);
        if (!is_array($job)) {
            Metrics::increment('queue.malformed');
            return null;
        }
        return $job;
    }

    public static function depth(string $queue): int
    {
        return RedisClient::instance()->llen($queue);
    }

    /** @param array<string, mixed> $job */
    private static function pushGearman(string $queue, array $job, string $payload): void
    {
        $function = self::gearmanFunction($queue);
        $unique = self::uniqueId($job);
        try {
            self::client()->submitBackground($function, $payload, $unique);
            return;
        } catch (GearmanException) {
            // The cached connection may be stale (gearmand restarted since
            // the last push) — retry ONCE on a fresh socket, mirroring
            // RedisClient::command().
            try {
                self::client(true)->submitBackground($function, $payload, $unique);
                return;
            } catch (GearmanException $e) {
                // gearman is the configured driver but gearmand is
                // unreachable at PUSH time: park the job in the Redis list
                // instead so it is NOT lost — the workers drain this
                // fallback list too (WorkerLoop), so jobs keep flowing
                // through the outage. Not user-visible: only when Redis is
                // ALSO down does the RPUSH below raise RedisException into
                // the caller's existing degraded path.
                Metrics::increment('queue.driver_fallback');
                error_log(sprintf(
                    '[%s] queue: gearmand unreachable at push time (%s) — job parked in redis fallback list %s',
                    instance_id(),
                    $e->getMessage(),
                    $queue
                ));
                RedisClient::instance()->rpush($queue, $payload);
            }
        }
    }

    /**
     * Production-style unique id: "<type>-<submission_id>-a<attempt>"
     * (+ "-<kind>" when the job carries one, e.g. autoresponder emails).
     * gearmand coalesces duplicate uniques; the attempt suffix keeps OUR
     * retry re-pushes from coalescing with the original job.
     *
     * @param array<string, mixed> $job
     */
    private static function uniqueId(array $job): string
    {
        $unique = sprintf(
            '%s-%s-a%d',
            (string) ($job['type'] ?? 'job'),
            (string) ($job['submission_id'] ?? '0'),
            (int) ($job['attempt'] ?? 0)
        );
        if (isset($job['kind']) && is_string($job['kind']) && $job['kind'] !== '') {
            $unique .= '-' . $job['kind'];
        }
        return $unique;
    }

    private static function client(bool $fresh = false): GearmanLite
    {
        if (self::$client === null) {
            self::$client = new GearmanLite();
        }
        if ($fresh || !self::$client->isConnected()) {
            self::$client->connect();
        }
        return self::$client;
    }

    private static function gearmandReachable(): bool
    {
        $sock = @stream_socket_client(
            sprintf(
                'tcp://%s:%d',
                Config::get('GEARMAN_HOST', '127.0.0.1'),
                (int) Config::get('GEARMAN_PORT', '4730')
            ),
            $errno,
            $errstr,
            0.2
        );
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }
}
