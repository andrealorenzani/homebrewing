<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\RequireAuthMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session\ArraySession;
use Tests\TestCase;

final class RequireAuthMiddlewareTest extends TestCase
{
    public function testRedirectsToLoginWhenNoSessionUser(): void
    {
        $middleware = new RequireAuthMiddleware(new ArraySession());

        $response = $middleware->handle(
            Request::create('GET', '/recipes/new'),
            static fn (Request $r): Response => Response::text('should-not-run'),
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function testUsesCustomLoginPath(): void
    {
        $middleware = new RequireAuthMiddleware(new ArraySession(), 'user_id', '/auth/login');

        $response = $middleware->handle(
            Request::create('GET', '/recipes/new'),
            static fn (Request $r): Response => Response::text('should-not-run'),
        );

        $this->assertSame('/auth/login', $response->getHeaders()['Location']);
    }

    public function testAttachesAuthUserIdAndContinuesWhenSessionUserPresent(): void
    {
        $session = new ArraySession(['user_id' => 42]);
        $middleware = new RequireAuthMiddleware($session);

        $captured = null;
        $response = $middleware->handle(
            Request::create('GET', '/recipes/new'),
            function (Request $r) use (&$captured): Response {
                $captured = $r->getAttribute('auth_user_id');

                return Response::text('ok');
            },
        );

        $this->assertSame(42, $captured);
        $this->assertSame('ok', $response->getBody());
    }

    public function testUsesCustomSessionKey(): void
    {
        $session = new ArraySession(['admin_id' => 7]);
        $middleware = new RequireAuthMiddleware($session, 'admin_id');

        $captured = null;
        $middleware->handle(
            Request::create('GET', '/'),
            function (Request $r) use (&$captured): Response {
                $captured = $r->getAttribute('auth_user_id');

                return Response::text('ok');
            },
        );

        $this->assertSame(7, $captured);
    }
}
