<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\OptionalAuthMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session\ArraySession;
use Tests\TestCase;

final class OptionalAuthMiddlewareTest extends TestCase
{
    public function testAttachesNullWhenNoSessionUser(): void
    {
        $middleware = new OptionalAuthMiddleware(new ArraySession());

        $captured = 'unset';
        $response = $middleware->handle(
            Request::create('GET', '/recipes/42'),
            function (Request $r) use (&$captured): Response {
                $captured = $r->getAttribute('auth_user_id');

                return Response::text('ok');
            },
        );

        $this->assertNull($captured);
        $this->assertSame('ok', $response->getBody());
    }

    public function testAttachesSessionUserIdWhenPresent(): void
    {
        $middleware = new OptionalAuthMiddleware(new ArraySession(['user_id' => 3]));

        $captured = null;
        $middleware->handle(
            Request::create('GET', '/recipes/42'),
            function (Request $r) use (&$captured): Response {
                $captured = $r->getAttribute('auth_user_id');

                return Response::text('ok');
            },
        );

        $this->assertSame(3, $captured);
    }

    public function testNeverBlocksRequestRegardlessOfSessionState(): void
    {
        $middleware = new OptionalAuthMiddleware(new ArraySession());

        $response = $middleware->handle(
            Request::create('GET', '/recipes/42'),
            static fn (Request $r): Response => Response::text('reached-handler'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('reached-handler', $response->getBody());
    }

    public function testUsesCustomSessionKey(): void
    {
        $middleware = new OptionalAuthMiddleware(new ArraySession(['visitor_id' => 99]), 'visitor_id');

        $captured = null;
        $middleware->handle(
            Request::create('GET', '/'),
            function (Request $r) use (&$captured): Response {
                $captured = $r->getAttribute('auth_user_id');

                return Response::text('ok');
            },
        );

        $this->assertSame(99, $captured);
    }
}
