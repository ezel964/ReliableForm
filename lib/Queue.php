<?php

declare(strict_types=1);

final class Queue
{
    public const QUEUE_PDF = 'queue:pdf';
    public const QUEUE_EMAIL = 'queue:email';
    public const QUEUE_WEBHOOK = 'queue:webhook';
    public const QUEUE_DEAD = 'queue:dead';

    /** @param array<string, mixed> $job */
    public static function push(string $queue, array $job): void
    {
        // Carry the trace across the async boundary; workers resume it via
        // Trace::startFromJob(). Extra key is ignored by older consumers.
        if (!isset($job['trace'])) {
            $job['trace'] = Trace::context();
        }
        RedisClient::instance()->rpush($queue, json_encode($job, JSON_THROW_ON_ERROR));
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
}
