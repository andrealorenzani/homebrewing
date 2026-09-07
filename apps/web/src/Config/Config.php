<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Minimal env/config loader.
 *
 * Reads simple `KEY=VALUE` lines from a `.env`-style file (comments starting
 * with `#` and blank lines are ignored, surrounding single/double quotes are
 * stripped). Real process environment variables (getenv()) always take
 * precedence over file values for a given key, so hosting environments that
 * inject configuration via the environment rather than a file still work.
 */
final class Config
{
    /** @var array<string, string> */
    private array $data;

    /**
     * @param array<string, string> $data
     */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromEnvFile(string $path): self
    {
        $data = [];

        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);

                if ($key === '') {
                    continue;
                }

                $data[$key] = self::stripQuotes(trim($value));
            }
        }

        foreach (array_keys($data) as $key) {
            $envValue = getenv($key);

            if ($envValue !== false) {
                $data[$key] = $envValue;
            }
        }

        return new self($data);
    }

    /**
     * @param array<string, string> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    private static function stripQuotes(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->data;
    }
}
