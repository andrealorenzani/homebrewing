<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Small method+path router with `{param}` segment support and a
 * middleware pipeline (global middleware registered via pipe(), plus
 * optional per-route middleware).
 */
final class Router
{
    /**
     * @var list<array{
     *     method: string,
     *     regex: string,
     *     params: list<string>,
     *     handler: callable(Request): Response,
     *     middleware: list<MiddlewareInterface|callable>
     * }>
     */
    private array $routes = [];

    /** @var list<MiddlewareInterface|callable> */
    private array $globalMiddleware = [];

    /**
     * @param callable(Request): Response $handler
     * @param list<MiddlewareInterface|callable> $middleware
     */
    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->map('GET', $path, $handler, $middleware);
    }

    /**
     * @param callable(Request): Response $handler
     * @param list<MiddlewareInterface|callable> $middleware
     */
    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->map('POST', $path, $handler, $middleware);
    }

    /**
     * @param callable(Request): Response $handler
     * @param list<MiddlewareInterface|callable> $middleware
     */
    public function put(string $path, callable $handler, array $middleware = []): void
    {
        $this->map('PUT', $path, $handler, $middleware);
    }

    /**
     * @param callable(Request): Response $handler
     * @param list<MiddlewareInterface|callable> $middleware
     */
    public function delete(string $path, callable $handler, array $middleware = []): void
    {
        $this->map('DELETE', $path, $handler, $middleware);
    }

    /**
     * @param callable(Request): Response $handler
     * @param list<MiddlewareInterface|callable> $middleware
     */
    public function map(string $method, string $path, callable $handler, array $middleware = []): void
    {
        [$regex, $paramNames] = $this->compile($path);

        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => $regex,
            'params' => $paramNames,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * Registers middleware that runs (in registration order) for every route.
     */
    public function pipe(MiddlewareInterface|callable $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->getMethod()) {
                continue;
            }

            if (preg_match($route['regex'], $request->getPath(), $matches) === 1) {
                $params = [];
                foreach ($route['params'] as $name) {
                    $params[$name] = $matches[$name] ?? null;
                }

                $requestWithParams = $request->withRouteParams($params);

                return $this->runPipeline($requestWithParams, $route['handler'], $route['middleware']);
            }
        }

        return Response::notFound();
    }

    /**
     * @param callable(Request): Response $handler
     * @param list<MiddlewareInterface|callable> $routeMiddleware
     */
    private function runPipeline(Request $request, callable $handler, array $routeMiddleware): Response
    {
        $stack = [...$this->globalMiddleware, ...$routeMiddleware];

        $next = $handler;

        foreach (array_reverse($stack) as $middleware) {
            $current = $next;
            $next = static function (Request $req) use ($middleware, $current): Response {
                if ($middleware instanceof MiddlewareInterface) {
                    return $middleware->handle($req, $current);
                }

                return $middleware($req, $current);
            };
        }

        return $next($request);
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function compile(string $path): array
    {
        $paramNames = [];

        $pattern = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $m) use (&$paramNames): string {
                $paramNames[] = $m[1];

                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $path,
        );

        return ['#^' . $pattern . '$#', $paramNames];
    }
}
