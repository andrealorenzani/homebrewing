<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\Config;
use App\Http\Request;
use App\Kernel;
use Tests\TestCase;

/**
 * Exercises the Kernel exactly as the front controller (public/index.php)
 * does, proving a CLI-driven request to /healthz returns 200 without
 * needing a real webserver.
 */
final class KernelTest extends TestCase
{
    public function testHealthzRouteReturns200(): void
    {
        $kernel = new Kernel(Config::fromArray([]));

        $response = $kernel->handle(Request::create('GET', '/healthz'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"status":"ok"}', $response->getBody());
    }

    public function testUnknownRouteReturns404(): void
    {
        $kernel = new Kernel(Config::fromArray([]));

        $response = $kernel->handle(Request::create('GET', '/does-not-exist'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testExposesConfigAndRouter(): void
    {
        $config = Config::fromArray(['APP_ENV' => 'testing']);
        $kernel = new Kernel($config);

        $this->assertSame($config, $kernel->getConfig());
        $this->assertNotNull($kernel->getRouter());
    }
}
