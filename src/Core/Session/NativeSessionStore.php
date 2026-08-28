<?php

declare(strict_types=1);

namespace N3\Core\Session;

use RuntimeException;

final class NativeSessionStore implements SessionStore
{
    private bool $started = false;

    public function __construct(
        private readonly string $path,
        private readonly bool $secureCookie,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function invalidate(): void
    {
        $this->start();
        $_SESSION = [];
        session_destroy();
        if (!headers_sent()) {
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => $this->secureCookie,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $this->started = false;
    }

    private function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        if (!is_dir($this->path) && !mkdir($this->path, 0700, true) && !is_dir($this->path)) {
            throw new RuntimeException('Unable to create the private session directory.');
        }

        chmod($this->path, 0700);
        session_save_path($this->path);
        session_name('n3_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        if (!session_start()) {
            throw new RuntimeException('Unable to start the session.');
        }

        $this->started = true;
    }
}
