<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Csrf\CsrfTokenManager;
use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Http\Response;

/**
 * Rejects unsafe (state-changing) requests that don't carry a valid CSRF
 * token, either as a `csrf_token` body field or an `X-CSRF-Token` header.
 * Safe methods (GET/HEAD/OPTIONS) pass through untouched.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly CsrfTokenManager $csrf)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $submitted = $request->getBodyParam($this->csrf->fieldName());

        if (!is_string($submitted) || $submitted === '') {
            $submitted = $request->getHeaderLine('X-CSRF-Token');
        }

        if (!$this->csrf->isValid(is_string($submitted) && $submitted !== '' ? $submitted : null)) {
            return Response::text('CSRF token mismatch', 419);
        }

        return $next($request);
    }
}
