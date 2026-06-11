<?php

declare(strict_types=1);

class RedisException extends RuntimeException
{
}

/**
 * Minimal pure-PHP Redis client (RESP2 over TCP). No phpredis/predis needed.
 * Covers exactly what ReliableForm uses: strings, lists, TTLs, BLPOP, PING.
 */
final class RedisClient
{
    private static ?self $shared = null;

    /** @var resource|null */
    private $sock = null;

    public static function instance(): self
    {
        if (self::$shared === null) {
            self::$shared = new self();
        }
        return self::$shared;
    }

    private function connect(): void
    {
        $host = Config::get('REDIS_HOST', '127.0.0.1');
        $port = Config::get('REDIS_PORT', '6379');
        $sock = @stream_socket_client(
            sprintf('tcp://%s:%s', $host, $port),
            $errno,
            $errstr,
            3.0
        );
        if ($sock === false) {
            throw new RedisException(sprintf('Redis connect to %s:%s failed: %s', $host, $port, $errstr));
        }
        stream_set_timeout($sock, 10);
        $this->sock = $sock;
    }

    private function close(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    /**
     * Send one command, return the parsed reply.
     * Retries once on a broken connection (stale socket from a previous request).
     *
     * @return mixed string|int|array|null depending on the command
     */
    public function command(string ...$args)
    {
        try {
            return $this->execute($args);
        } catch (RedisException $e) {
            $this->close();
            return $this->execute($args);
        }
    }

    /** @param list<string> $args */
    private function execute(array $args)
    {
        if (!is_resource($this->sock)) {
            $this->connect();
        }
        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $payload .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        $this->writeAll($payload);
        return $this->readReply();
    }

    private function writeAll(string $payload): void
    {
        $remaining = $payload;
        while ($remaining !== '') {
            $written = @fwrite($this->sock, $remaining);
            if ($written === false || $written === 0) {
                throw new RedisException('Redis write failed');
            }
            $remaining = substr($remaining, $written);
        }
    }

    private function readLine(): string
    {
        $line = @fgets($this->sock);
        if ($line === false) {
            $meta = is_resource($this->sock) ? stream_get_meta_data($this->sock) : ['timed_out' => false];
            $why = !empty($meta['timed_out']) ? 'timed out' : 'connection lost';
            throw new RedisException('Redis read failed: ' . $why);
        }
        return rtrim($line, "\r\n");
    }

    /** @return mixed */
    private function readReply()
    {
        $line = $this->readLine();
        $type = $line[0] ?? '';
        $rest = substr($line, 1);
        switch ($type) {
            case '+':
                return $rest;
            case ':':
                return (int) $rest;
            case '-':
                throw new RedisException('Redis error reply: ' . $rest);
            case '$':
                $len = (int) $rest;
                if ($len === -1) {
                    return null;
                }
                $data = '';
                $toRead = $len + 2; // payload + trailing CRLF
                while (strlen($data) < $toRead) {
                    $chunk = @fread($this->sock, $toRead - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        throw new RedisException('Redis bulk read failed');
                    }
                    $data .= $chunk;
                }
                return substr($data, 0, $len);
            case '*':
                $count = (int) $rest;
                if ($count === -1) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $items[] = $this->readReply();
                }
                return $items;
            default:
                throw new RedisException('Redis protocol error: unexpected reply ' . $line);
        }
    }

    public function ping(): bool
    {
        return $this->command('PING') === 'PONG';
    }

    public function get(string $key): ?string
    {
        $v = $this->command('GET', $key);
        return $v === null ? null : (string) $v;
    }

    public function set(string $key, string $value): bool
    {
        return $this->command('SET', $key, $value) === 'OK';
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        return $this->command('SETEX', $key, (string) $ttl, $value) === 'OK';
    }

    public function del(string ...$keys): int
    {
        return (int) $this->command('DEL', ...$keys);
    }

    public function incr(string $key): int
    {
        return (int) $this->command('INCR', $key);
    }

    public function expire(string $key, int $ttl): int
    {
        return (int) $this->command('EXPIRE', $key, (string) $ttl);
    }

    public function ttl(string $key): int
    {
        return (int) $this->command('TTL', $key);
    }

    public function llen(string $key): int
    {
        return (int) $this->command('LLEN', $key);
    }

    public function rpush(string $key, string ...$values): int
    {
        return (int) $this->command('RPUSH', $key, ...$values);
    }

    public function lpush(string $key, string ...$values): int
    {
        return (int) $this->command('LPUSH', $key, ...$values);
    }

    /** @return list<string> */
    public function lrange(string $key, int $start, int $stop): array
    {
        $v = $this->command('LRANGE', $key, (string) $start, (string) $stop);
        return is_array($v) ? $v : [];
    }

    /**
     * Blocking pop. The socket read timeout is raised above $timeout so a
     * quiet queue is reported as null, not as a connection error.
     *
     * @return array{0: string, 1: string}|null [key, value]
     */
    public function blpop(string $key, int $timeout): ?array
    {
        if (!is_resource($this->sock)) {
            $this->connect();
        }
        stream_set_timeout($this->sock, $timeout + 10);
        try {
            $v = $this->command('BLPOP', $key, (string) $timeout);
        } finally {
            if (is_resource($this->sock)) {
                stream_set_timeout($this->sock, 10);
            }
        }
        if (!is_array($v) || count($v) !== 2) {
            return null;
        }
        return [(string) $v[0], (string) $v[1]];
    }
}
