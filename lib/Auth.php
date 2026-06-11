<?php

declare(strict_types=1);

final class Auth
{
    /** @var array<string, mixed>|null */
    private static ?array $cached = null;
    private static bool $resolved = false;

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        Session::start();
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }
        if (self::$resolved && self::$cached !== null && (int) self::$cached['id'] === (int) $id) {
            return self::$cached;
        }
        self::$resolved = true;
        self::$cached = DB::one(
            'SELECT id, email, name, created_at FROM users WHERE id = ?',
            [(int) $id]
        );
        if (self::$cached === null) {
            unset($_SESSION['user_id']);
        }
        return self::$cached;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** @return array<string, mixed> */
    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            flash('info', 'Please log in first.');
            redirect('/login');
        }
        return $user;
    }

    public static function attempt(string $email, string $password): bool
    {
        Session::start();
        if (self::isRateLimited()) {
            Metrics::increment('web.login.ratelimited');
            return false;
        }
        $user = DB::one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            Metrics::increment('web.login.fail');
            return false;
        }
        session_regenerate_id(true); // session fixation defense
        $_SESSION['user_id'] = (int) $user['id'];
        self::$resolved = false;
        Metrics::increment('web.login.success');
        return true;
    }

    private static function isRateLimited(): bool
    {
        $key = 'ratelimit:login:' . request_ip();
        try {
            $redis = RedisClient::instance();
            $count = $redis->incr($key);
            if ($count === 1) {
                $redis->expire($key, 60);
            }
            return $count > 10;
        } catch (RedisException) {
            return false; // Redis down: sessions are broken anyway; don't double-fail logins
        }
    }

    /** @return array{ok: bool, error: ?string} */
    public static function register(string $name, string $email, string $password): array
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || mb_strlen($name) > 100) {
            return ['ok' => false, 'error' => 'Please tell us your name (100 characters max).'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'That email address does not look valid.'];
        }
        if (strlen($password) < 8) {
            return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        }
        try {
            $id = DB::insert(
                'INSERT INTO users (email, name, password_hash, api_key) VALUES (?, ?, ?, ?)',
                [$email, $name, password_hash($password, PASSWORD_BCRYPT), generate_api_key()]
            );
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                return ['ok' => false, 'error' => 'That email is already registered. Try logging in.'];
            }
            throw $e;
        }
        Session::start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        self::$resolved = false;
        Metrics::increment('web.register');
        return ['ok' => true, 'error' => null];
    }

    public static function logout(): void
    {
        Session::start();
        $_SESSION = [];
        session_regenerate_id(true);
        session_destroy();
        self::$cached = null;
        self::$resolved = false;
        Metrics::increment('web.logout');
    }
}
