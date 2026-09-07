<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session\SessionInterface;

/**
 * "Auth required" guard: blocks the request (redirecting to the login
 * page) unless the session has a logged-in user id, otherwise attaches
 * that id to the request as the `auth_user_id` attribute and continues.
 *
 * Deliberately just a session-user-id lookup — no User entity/repository
 * hydration here; that's for later, DB-dependent tasks to add on top.
 */
final class RequireAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionInterface $session,
        private readonly string $sessionKey = 'user_id',
        private readonly string $loginPath = '/login',
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $userId = $this->session->get($this->sessionKey);

        if ($userId === null) {
            return Response::redirect($this->loginPath);
        }

        return $next($request->withAttribute('auth_user_id', $userId));
    }
}
