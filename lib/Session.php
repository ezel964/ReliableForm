<?php

declare(strict_types=1);

/**
 * Sessions shared across web1/web2 behind the load balancer.
 *
 * SESSION_DRIVER=mysql (default) keeps them in the `sessions` table —
 * matching the production split where MySQL owns sessions and Redis is
 * cache/rate-limit only. SESSION_DRIVER=redis keeps the original
 * sess:<sid> keys with native TTL expiry.
 */
final class Session
{
    public static function start(): void
    {
        if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
            return; // workers never need a session
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $handler = Config::get('SESSION_DRIVER', 'mysql') === 'redis'
            ? new RedisSessionHandler()
            : new MysqlSessionHandler();
        session_set_save_handler($handler, true);
        // The MySQL driver relies on PHP invoking gc(); some distros ship
        // with the probability zeroed out, so pin a sane default.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_name('rfsession');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function ttl(): int
    {
        return max(60, (int) Config::get('SESSION_TTL', '86400'));
    }
}

final class MysqlSessionHandler implements SessionHandlerInterface
{
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
        $row = DB::one('SELECT payload FROM sessions WHERE sid = ? AND expires_at > NOW()', [$id]);
        return $row === null ? '' : (string) $row['payload'];
    }

    public function write(string $id, string $data): bool
    {
        // VALUES() over the 8.0.19 alias syntax: MariaDB (apt installs) has
        // no row alias, and on MySQL 8 VALUES() is only a deprecation note.
        DB::run(
            'INSERT INTO sessions (sid, payload, expires_at)
             VALUES (?, ?, NOW() + INTERVAL ? SECOND)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)',
            [$id, $data, Session::ttl()]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        DB::run('DELETE FROM sessions WHERE sid = ?', [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return DB::run('DELETE FROM sessions WHERE expires_at < NOW()')->rowCount();
    }
}

final class RedisSessionHandler implements SessionHandlerInterface
{
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
        return RedisClient::instance()->setex('sess:' . $id, Session::ttl(), $data);
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
