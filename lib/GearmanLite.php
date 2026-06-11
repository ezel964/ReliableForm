<?php

declare(strict_types=1);

class GearmanException extends RuntimeException
{
}

/**
 * Minimal pure-PHP Gearman client/worker — binary protocol over TCP (port
 * 4730), no pecl extension needed. Mirrors the RedisClient approach: one
 * lazily-connected socket, explicit exceptions on IO errors.
 *
 * Packet framing: magic "\0REQ" (us → gearmand) / "\0RES" (gearmand → us) +
 * uint32 big-endian packet type + uint32 big-endian payload size, payload =
 * arguments joined with "\0". The LAST argument may itself contain NUL bytes
 * (job payloads), so responses are always split with an arg-count limit.
 *
 * The admin "text" protocol (status) shares the same port: gearmand sniffs
 * the first bytes of each request, so text commands and binary packets can
 * interleave on one connection.
 *
 * Reconnect-friendly: connect() always opens a fresh socket; callers own the
 * retry policy (Queue retries a push once, WorkerLoop reconnects per cycle).
 */
final class GearmanLite
{
    // Binary packet types (the subset ReliableForm uses).
    public const CAN_DO = 1;
    public const PRE_SLEEP = 4;
    public const NOOP = 6;
    public const JOB_CREATED = 8;
    public const GRAB_JOB = 9;
    public const NO_JOB = 10;
    public const JOB_ASSIGN = 11;
    public const WORK_COMPLETE = 13;
    public const WORK_FAIL = 14;
    public const ECHO_REQ = 16;
    public const ECHO_RES = 17;
    public const SUBMIT_JOB_BG = 18;

    private const MAGIC_REQ = "\0REQ";
    private const MAGIC_RES = "\0RES";
    private const HEADER_LEN = 12;
    private const REPLY_TIMEOUT = 10.0; // seconds — gearmand answers requests immediately

    /** @var resource|null */
    private $sock = null;
    private string $host;
    private int $port;
    private float $connectTimeout;

    public function __construct(?string $host = null, ?int $port = null, float $connectTimeout = 3.0)
    {
        $this->host = $host ?? (Config::get('GEARMAN_HOST', '127.0.0.1') ?? '127.0.0.1');
        $this->port = $port ?? (int) (Config::get('GEARMAN_PORT', '4730') ?? '4730');
        $this->connectTimeout = $connectTimeout;
    }

    /** Always opens a FRESH socket (drops any previous one). */
    public function connect(): void
    {
        $this->close();
        $sock = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            $this->connectTimeout
        );
        if ($sock === false) {
            throw new GearmanException(sprintf(
                'gearmand connect to %s:%d failed: %s',
                $this->host,
                $this->port,
                $errstr !== '' ? $errstr : 'errno ' . $errno
            ));
        }
        $this->sock = $sock;
        $this->setReadTimeout(self::REPLY_TIMEOUT);
    }

    public function isConnected(): bool
    {
        return is_resource($this->sock);
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    // -- client ---------------------------------------------------------------

    /**
     * SUBMIT_JOB_BG(18) → JOB_CREATED(8). Returns the job handle. gearmand
     * coalesces submissions sharing $unique while the original is still
     * queued/running (production-style unique-id dedup).
     */
    public function submitBackground(string $function, string $payload, string $unique): string
    {
        $this->sendPacket(self::SUBMIT_JOB_BG, [$function, $unique, $payload]);
        [$type, $body] = $this->readPacket(self::REPLY_TIMEOUT);
        if ($type !== self::JOB_CREATED) {
            $this->close();
            throw new GearmanException('expected JOB_CREATED, got packet type ' . $type);
        }
        return $body; // single argument: the handle
    }

    // -- worker ---------------------------------------------------------------

    /** CAN_DO(1) per function. gearmand sends no response. */
    public function register(string ...$functions): void
    {
        foreach ($functions as $function) {
            $this->sendPacket(self::CAN_DO, [$function]);
        }
    }

    /**
     * GRAB_JOB(9) → JOB_ASSIGN(11) | NO_JOB(10) → PRE_SLEEP(4) → block for
     * NOOP(6) with a socket read timeout of the remaining idle budget. A
     * quiet timeout returns null so the caller heartbeats and re-grabs —
     * the same cadence contract as Queue::pop($q, 5).
     *
     * @return array{handle: string, function: string, payload: string}|null
     */
    public function grab(int $idleTimeout): ?array
    {
        $deadline = microtime(true) + max(1, $idleTimeout);
        while (true) {
            $this->sendPacket(self::GRAB_JOB, []);
            // Stray NOOPs (sent while we were between PRE_SLEEP and GRAB_JOB)
            // may precede the actual GRAB_JOB response — skip them.
            do {
                [$type, $body] = $this->readPacket(self::REPLY_TIMEOUT);
            } while ($type === self::NOOP);

            if ($type === self::JOB_ASSIGN) {
                // handle \0 function \0 payload — payload (last) may contain NULs.
                $args = explode("\0", $body, 3);
                if (count($args) !== 3) {
                    $this->close();
                    throw new GearmanException('malformed JOB_ASSIGN packet');
                }
                return ['handle' => $args[0], 'function' => $args[1], 'payload' => $args[2]];
            }
            if ($type !== self::NO_JOB) {
                $this->close();
                throw new GearmanException('unexpected packet type ' . $type . ' answering GRAB_JOB');
            }

            $remaining = $deadline - microtime(true);
            if ($remaining < 0.05) {
                return null;
            }
            $this->sendPacket(self::PRE_SLEEP, []);
            $woke = $this->readPacketOrNull($remaining);
            if ($woke === null) {
                return null; // idle timeout — caller heartbeats and re-grabs
            }
            // NOOP (or anything else): loop and GRAB_JOB again until deadline.
        }
    }

    /**
     * WORK_COMPLETE(13). The protocol requires a result argument — empty
     * string for our fire-and-forget background jobs. No response packet.
     */
    public function complete(string $handle, string $result = ''): void
    {
        $this->sendPacket(self::WORK_COMPLETE, [$handle, $result]);
    }

    /** WORK_FAIL(14) — transport-level failures only (see WorkerLoop). */
    public function fail(string $handle): void
    {
        $this->sendPacket(self::WORK_FAIL, [$handle]);
    }

    // -- admin (text protocol, same port) --------------------------------------

    /**
     * `status` — one line per function, "name\tqueued\trunning\tworkers",
     * terminated by a lone ".". Note: gearmand's second column is the TOTAL
     * job count for the function (queued + running); the contract labels it
     * "queued" and the probes treat it as the queue depth.
     *
     * @return array<string, array{queued: int, running: int, workers: int}>
     */
    public function status(): array
    {
        $this->ensureConnected();
        $this->setReadTimeout(self::REPLY_TIMEOUT);
        $this->writeAll("status\n");
        $functions = [];
        while (true) {
            $line = @fgets($this->sock);
            if ($line === false) {
                $this->close();
                throw new GearmanException('gearmand admin status read failed');
            }
            $line = rtrim($line, "\r\n");
            if ($line === '.') {
                break;
            }
            $parts = explode("\t", $line);
            if (count($parts) !== 4 || $parts[0] === '') {
                continue; // tolerate unknown admin chatter
            }
            $functions[$parts[0]] = [
                'queued' => (int) $parts[1],
                'running' => (int) $parts[2],
                'workers' => (int) $parts[3],
            ];
        }
        return $functions;
    }

    /** Connectivity check: ECHO_REQ(16) → ECHO_RES(17) roundtrip. */
    public function ping(): bool
    {
        try {
            $probe = 'rf-ping-' . getmypid() . '-' . bin2hex(random_bytes(4));
            $this->sendPacket(self::ECHO_REQ, [$probe]);
            [$type, $body] = $this->readPacket(self::REPLY_TIMEOUT);
            return $type === self::ECHO_RES && $body === $probe;
        } catch (GearmanException) {
            return false;
        }
    }

    // -- internals --------------------------------------------------------------

    private function ensureConnected(): void
    {
        if (!is_resource($this->sock)) {
            $this->connect();
        }
    }

    /** @param list<string> $args */
    private function sendPacket(int $type, array $args): void
    {
        $this->ensureConnected();
        $body = implode("\0", $args);
        $this->writeAll(self::MAGIC_REQ . pack('NN', $type, strlen($body)) . $body);
    }

    private function writeAll(string $payload): void
    {
        $remaining = $payload;
        while ($remaining !== '') {
            $written = @fwrite($this->sock, $remaining);
            if ($written === false || $written === 0) {
                $this->close();
                throw new GearmanException('gearmand write failed');
            }
            $remaining = substr($remaining, $written);
        }
    }

    /** @return array{0: int, 1: string} [packet type, raw args body] */
    private function readPacket(float $timeout): array
    {
        $packet = $this->readPacketOrNull($timeout, false);
        if ($packet === null) {
            // unreachable (nullOnTimeout=false throws), but keeps phpstan honest
            throw new GearmanException('gearmand read failed: timed out');
        }
        return $packet;
    }

    /**
     * Read one "\0RES" packet. A clean read timeout BEFORE the first byte
     * returns null when $nullOnTimeout (the worker idle path); every other
     * failure — mid-packet timeout, connection loss, bad magic — closes the
     * socket and throws.
     *
     * @return array{0: int, 1: string}|null
     */
    private function readPacketOrNull(float $timeout, bool $nullOnTimeout = true): ?array
    {
        $this->setReadTimeout($timeout);
        $header = $this->readBytes(self::HEADER_LEN, $nullOnTimeout);
        if ($header === null) {
            return null;
        }
        if (substr($header, 0, 4) !== self::MAGIC_RES) {
            $this->close();
            throw new GearmanException('gearmand protocol desync: bad packet magic');
        }
        /** @var array{type: int, size: int} $meta */
        $meta = unpack('Ntype/Nsize', substr($header, 4));
        $body = $meta['size'] > 0 ? $this->readBytes($meta['size'], false) : '';
        return [$meta['type'], (string) $body];
    }

    /** @return string|null null only when $nullOnCleanTimeout and zero bytes arrived */
    private function readBytes(int $n, bool $nullOnCleanTimeout): ?string
    {
        $data = '';
        while (strlen($data) < $n) {
            $chunk = @fread($this->sock, $n - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = is_resource($this->sock) ? stream_get_meta_data($this->sock) : ['timed_out' => false];
                $timedOut = !empty($meta['timed_out']);
                if ($timedOut && $data === '' && $nullOnCleanTimeout) {
                    return null;
                }
                $this->close();
                throw new GearmanException('gearmand read failed: ' . ($timedOut ? 'timed out' : 'connection lost'));
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function setReadTimeout(float $seconds): void
    {
        if (!is_resource($this->sock)) {
            return;
        }
        $seconds = max(0.05, $seconds);
        stream_set_timeout($this->sock, (int) $seconds, (int) round(fmod($seconds, 1.0) * 1e6));
    }
}
