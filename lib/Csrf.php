<?php

declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        Session::start();
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    /** Call on every POST request before doing any work. */
    public static function validate(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        $sent = $_POST['_token'] ?? '';
        if (!is_string($sent) || $sent === '' || !hash_equals(self::token(), $sent)) {
            Metrics::increment('web.csrf.reject');
            http_response_code(419);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Your session expired or the form token was invalid. Go back, refresh the page and try again.\n";
            exit;
        }
    }
}
