<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session\SessionInterface;

/**
 * "Optional auth" resolver: attaches the current user id to the request
 * (as the `auth_user_id` attribute, or null if there is no session user)
 * without ever blocking or redirecting.
 *
 * Used by routes that must serve both an owning user and anonymous/other
 * visitors (e.g. viewing a recipe or batch), where visibility enforcement
 * happens later in the service layer, not here.
 */
final class OptionalAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionInterface $session,
        private readonly string $sessionKey = 'user_id',
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $userId = $this->session->get($this->sessionKey);

        return $next($request->withAttribute('auth_user_id', $userId));
    }
}
