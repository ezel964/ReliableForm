<?php

declare(strict_types=1);

/**
 * Redis-backed sessions so web1 and web2 share login state behind the
 * load balancer. Keys: sess:<sid>, TTL = SESSION_TTL.
 */
final class Session implements SessionHandlerInterface
{
    public static function start(): void
    {
        if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
            return; // workers never need a session
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_save_handler(new self(), true);
        session_name('rfsession');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    private function ttl(): int
    {
        return max(60, (int) Config::get('SESSION_TTL', '86400'));
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        return RedisClient::instance()->get('sess:' . $id) ?? '';
    }

    public function write(string $id, string $data): bool
    {
        return RedisClient::instance()->setex('sess:' . $id, $this->ttl(), $data);
    }

    public function destroy(string $id): bool
    {
        RedisClient::instance()->del('sess:' . $id);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0; // Redis TTLs handle expiry
    }
}
