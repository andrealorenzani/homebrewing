<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Csrf\CsrfTokenManager;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session\ArraySession;
use Tests\TestCase;

final class CsrfMiddlewareTest extends TestCase
{
    private function next(): callable
    {
        return static fn (Request $r): Response => Response::text('handled');
    }

    public function testSafeMethodsPassThroughWithoutToken(): void
    {
        $csrf = new CsrfTokenManager(new ArraySession());
        $middleware = new CsrfMiddleware($csrf);

        $response = $middleware->handle(Request::create('GET', '/'), $this->next());

        $this->assertSame('handled', $response->getBody());
    }

    public function testUnsafeMethodWithValidTokenInBodyPasses(): void
    {
        $session = new ArraySession();
        $csrf = new CsrfTokenManager($session);
        $token = $csrf->getToken();
        $middleware = new CsrfMiddleware($csrf);

        $request = Request::create('POST', '/login', [], ['csrf_token' => $token]);
        $response = $middleware->handle($request, $this->next());

        $this->assertSame('handled', $response->getBody());
    }

    public function testUnsafeMethodWithValidTokenInHeaderPasses(): void
    {
        $session = new ArraySession();
        $csrf = new CsrfTokenManager($session);
        $token = $csrf->getToken();
        $middleware = new CsrfMiddleware($csrf);

        $request = Request::create('POST', '/login', [], [], ['X-CSRF-Token' => $token]);
        $response = $middleware->handle($request, $this->next());

        $this->assertSame('handled', $response->getBody());
    }

    public function testUnsafeMethodWithMissingTokenIsRejected(): void
    {
        $csrf = new CsrfTokenManager(new ArraySession());
        $csrf->getToken();
        $middleware = new CsrfMiddleware($csrf);

        $response = $middleware->handle(Request::create('POST', '/login'), $this->next());

        $this->assertSame(419, $response->getStatusCode());
    }

    public function testUnsafeMethodWithWrongTokenIsRejected(): void
    {
        $csrf = new CsrfTokenManager(new ArraySession());
        $csrf->getToken();
        $middleware = new CsrfMiddleware($csrf);

        $request = Request::create('POST', '/login', [], ['csrf_token' => 'wrong']);
        $response = $middleware->handle($request, $this->next());

        $this->assertSame(419, $response->getStatusCode());
    }
}
