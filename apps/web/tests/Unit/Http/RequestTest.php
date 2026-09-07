<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Request;
use Tests\TestCase;

final class RequestTest extends TestCase
{
    public function testCreateNormalizesMethodAndPath(): void
    {
        $request = Request::create('get', '/healthz/');

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/healthz', $request->getPath());
    }

    public function testRootPathStaysRoot(): void
    {
        $request = Request::create('GET', '/');

        $this->assertSame('/', $request->getPath());
    }

    public function testPathWithoutLeadingSlashGetsOne(): void
    {
        $request = Request::create('GET', 'healthz');

        $this->assertSame('/healthz', $request->getPath());
    }

    public function testQueryAndBodyParamsAccessible(): void
    {
        $request = Request::create('POST', '/login', ['redirect' => '/home'], ['username' => 'alice']);

        $this->assertSame(['redirect' => '/home'], $request->getQueryParams());
        $this->assertSame('/home', $request->getQueryParam('redirect'));
        $this->assertNull($request->getQueryParam('missing'));

        $this->assertSame(['username' => 'alice'], $request->getParsedBody());
        $this->assertSame('alice', $request->getBodyParam('username'));
        $this->assertSame('fallback', $request->getBodyParam('missing', 'fallback'));
    }

    public function testCookiesAccessible(): void
    {
        $request = Request::create('GET', '/', [], [], [], ['session_id' => 'abc']);

        $this->assertSame('abc', $request->getCookie('session_id'));
        $this->assertNull($request->getCookie('missing'));
    }

    public function testHeaderLineIsCaseInsensitive(): void
    {
        $request = Request::create('POST', '/', [], [], ['X-CSRF-Token' => 'tok123']);

        $this->assertSame('tok123', $request->getHeaderLine('x-csrf-token'));
        $this->assertSame('', $request->getHeaderLine('x-missing'));
    }

    public function testRouteParamsDefaultEmptyAndCanBeAttached(): void
    {
        $request = Request::create('GET', '/recipes/42');

        $this->assertSame([], $request->getRouteParams());
        $this->assertNull($request->getRouteParam('id'));

        $withParams = $request->withRouteParams(['id' => '42']);

        // Original request is unaffected (immutability).
        $this->assertSame([], $request->getRouteParams());
        $this->assertSame('42', $withParams->getRouteParam('id'));
        $this->assertSame(['id' => '42'], $withParams->getRouteParams());
    }

    public function testAttributesDefaultNullAndCanBeAttached(): void
    {
        $request = Request::create('GET', '/');

        $this->assertNull($request->getAttribute('auth_user_id'));

        $withAttribute = $request->withAttribute('auth_user_id', 7);

        $this->assertNull($request->getAttribute('auth_user_id'));
        $this->assertSame(7, $withAttribute->getAttribute('auth_user_id'));
        $this->assertSame('default', $withAttribute->getAttribute('missing', 'default'));
    }

    public function testFromGlobalsReadsSuperglobals(): void
    {
        $server = $_SERVER;
        $get = $_GET;
        $post = $_POST;
        $cookie = $_COOKIE;

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = '/healthz?x=1';
            $_SERVER['HTTP_X_CSRF_TOKEN'] = 'abc';
            $_GET = ['x' => '1'];
            $_POST = ['y' => '2'];
            $_COOKIE = ['c' => '3'];

            $request = Request::fromGlobals();

            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('/healthz', $request->getPath());
            $this->assertSame('1', $request->getQueryParam('x'));
            $this->assertSame('2', $request->getBodyParam('y'));
            $this->assertSame('3', $request->getCookie('c'));
            $this->assertSame('abc', $request->getHeaderLine('X-CSRF-Token'));
            $this->assertSame('POST', $request->getServerParam('REQUEST_METHOD'));
        } finally {
            $_SERVER = $server;
            $_GET = $get;
            $_POST = $post;
            $_COOKIE = $cookie;
        }
    }

    public function testFromGlobalsDefaultsMethodAndPath(): void
    {
        $server = $_SERVER;

        try {
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

            $request = Request::fromGlobals();

            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('/', $request->getPath());
        } finally {
            $_SERVER = $server;
        }
    }
}
