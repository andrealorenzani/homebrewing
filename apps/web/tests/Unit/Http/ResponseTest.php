<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Response;
use Tests\TestCase;

final class ResponseTest extends TestCase
{
    public function testTextResponse(): void
    {
        $response = Response::text('hello', 201);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('hello', $response->getBody());
        $this->assertSame(['Content-Type' => 'text/plain; charset=utf-8'], $response->getHeaders());
    }

    public function testHtmlResponseDefaultsTo200(): void
    {
        $response = Response::html('<p>hi</p>');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<p>hi</p>', $response->getBody());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaders()['Content-Type']);
    }

    public function testJsonResponseEncodesData(): void
    {
        $response = Response::json(['status' => 'ok'], 200);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"status":"ok"}', $response->getBody());
        $this->assertSame('application/json', $response->getHeaders()['Content-Type']);
    }

    public function testRedirectSetsLocationHeaderAndDefaultStatus(): void
    {
        $response = Response::redirect('/login');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('', $response->getBody());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function testRedirectAllowsCustomStatus(): void
    {
        $response = Response::redirect('/login', 301);

        $this->assertSame(301, $response->getStatusCode());
    }

    public function testNotFoundDefaults(): void
    {
        $response = Response::notFound();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $response->getBody());
    }

    public function testNotFoundAllowsCustomBody(): void
    {
        $response = Response::notFound('nope');

        $this->assertSame('nope', $response->getBody());
    }

    public function testWithHeaderReturnsNewInstanceWithAddedHeader(): void
    {
        $response = Response::text('hi');
        $withHeader = $response->withHeader('X-Test', 'value');

        $this->assertArrayNotHasKey('X-Test', $response->getHeaders());
        $this->assertSame('value', $withHeader->getHeaders()['X-Test']);
    }

    public function testSendEchoesBody(): void
    {
        $response = Response::text('body-content', 200);

        ob_start();
        $response->send();
        $output = ob_get_clean();

        $this->assertSame('body-content', $output);
    }
}
