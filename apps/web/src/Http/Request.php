<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Immutable-ish HTTP request value object.
 *
 * Route parameters and request attributes (e.g. the resolved auth user id
 * attached by an auth middleware) are added via with*() methods that return
 * a cloned instance, so middleware pipelines can thread additional context
 * through to the next handler without mutating shared state.
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $routeParams = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $parsedBody
     * @param array<string, mixed> $cookies
     * @param array<string, string> $headers lower-cased header name => value
     * @param array<string, mixed> $server
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $parsedBody = [],
        private readonly array $cookies = [],
        private readonly array $headers = [],
        private readonly array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        return new self(
            $method,
            self::normalizePath($path),
            $_GET,
            $_POST,
            $_COOKIE,
            $headers,
            $_SERVER,
        );
    }

    /**
     * Convenience factory for tests and CLI-driven simulated requests.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $parsedBody
     * @param array<string, string> $headers
     * @param array<string, mixed> $cookies
     */
    public static function create(
        string $method,
        string $path,
        array $query = [],
        array $parsedBody = [],
        array $headers = [],
        array $cookies = [],
    ): self {
        return new self(
            strtoupper($method),
            self::normalizePath($path),
            $query,
            $parsedBody,
            $cookies,
            array_change_key_case($headers, CASE_LOWER),
            [],
        );
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->query;
    }

    public function getQueryParam(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParsedBody(): array
    {
        return $this->parsedBody;
    }

    public function getBodyParam(string $key, mixed $default = null): mixed
    {
        return $this->parsedBody[$key] ?? $default;
    }

    public function getCookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function getHeaderLine(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function getServerParam(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;

        return $clone;
    }

    public function getRouteParam(string $name, mixed $default = null): mixed
    {
        return $this->routeParams[$name] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;

        return $clone;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
