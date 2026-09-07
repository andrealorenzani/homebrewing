<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A single link in the request-handling pipeline. Implementations must
 * either return a Response themselves (short-circuiting the pipeline, e.g.
 * a redirect to a login page) or call $next($request) to continue.
 */
interface MiddlewareInterface
{
    /**
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
