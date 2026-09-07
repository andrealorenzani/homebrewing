<?php

declare(strict_types=1);

namespace App\Http\Session;

/**
 * SessionInterface backed by PHP's native session ($_SESSION), started on
 * construction if not already active.
 */
final class PhpSession implements SessionInterface
{
    public function __construct(?string $name = null)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if ($name !== null && $name !== '') {
                session_name($name);
            }

            session_start();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerateId(): void
    {
        session_regenerate_id(true);
    }
}
