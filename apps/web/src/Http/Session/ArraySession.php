<?php

declare(strict_types=1);

namespace App\Http\Session;

/**
 * In-memory SessionInterface implementation used by tests (and, in
 * principle, by any CLI context that has no real HTTP session).
 */
final class ArraySession implements SessionInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerateId(): void
    {
        // Nothing to rotate for an in-memory double.
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }
}
