<?php

declare(strict_types=1);

namespace App\Http\Session;

/**
 * Small abstraction over session storage so HTTP-layer code (CSRF, auth
 * middleware) can be unit tested with an in-memory double instead of a
 * real PHP session.
 */
interface SessionInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function remove(string $key): void;

    /**
     * Rotates the session id (e.g. after login) to mitigate session
     * fixation. No-op for in-memory test doubles.
     */
    public function regenerateId(): void;
}
