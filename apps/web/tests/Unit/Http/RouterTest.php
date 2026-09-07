<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\MiddlewareInterface;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use Tests\TestCase;

final class RouterTest extends TestCase
{
    public function testDispatchesMatchingStaticRoute(): void
    {
        $router = new Router();
        $router->get('/healthz', static fn (Request $r): Response => Response::json(['status' => 'ok']));

        $response = $router->dispatch(Request::create('GET', '/healthz'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"status":"ok"}', $response->getBody());
    }

    public function testReturns404WhenNoRouteMatchesPath(): void
    {
        $router = new Router();
        $router->get('/healthz', static fn (Request $r): Response => Response::json([]));

        $response = $router->dispatch(Request::create('GET', '/nope'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReturns404WhenMethodDoesNotMatch(): void
    {
        $router = new Router();
        $router->get('/healthz', static fn (Request $r): Response => Response::json([]));

        $response = $router->dispatch(Request::create('POST', '/healthz'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDynamicSegmentsAreExtractedAsRouteParams(): void
    {
        $router = new Router();
        $router->get('/recipes/{id}', static function (Request $r): Response {
            return Response::text('recipe-' . $r->getRouteParam('id'));
        });

        $response = $router->dispatch(Request::create('GET', '/recipes/42'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('recipe-42', $response->getBody());
    }

    public function testPostPutDeleteHelpersRegisterExpectedMethods(): void
    {
        $router = new Router();
        $router->post('/a', static fn (Request $r): Response => Response::text('post'));
        $router->put('/b', static fn (Request $r): Response => Response::text('put'));
        $router->delete('/c', static fn (Request $r): Response => Response::text('delete'));

        $this->assertSame('post', $router->dispatch(Request::create('POST', '/a'))->getBody());
        $this->assertSame('put', $router->dispatch(Request::create('PUT', '/b'))->getBody());
        $this->assertSame('delete', $router->dispatch(Request::create('DELETE', '/c'))->getBody());
    }

    public function testGlobalMiddlewareRunsForEveryRoute(): void
    {
        $router = new Router();
        $log = [];

        $router->pipe(static function (Request $r, callable $next) use (&$log): Response {
            $log[] = 'global';

            return $next($r);
        });

        $router->get('/x', static function (Request $r) use (&$log): Response {
            $log[] = 'handler';

            return Response::text('ok');
        });

        $router->dispatch(Request::create('GET', '/x'));

        $this->assertSame(['global', 'handler'], $log);
    }

    public function testRouteMiddlewareRunsBeforeHandlerAndCanShortCircuit(): void
    {
        $router = new Router();

        $blocking = new class implements MiddlewareInterface {
            public function handle(Request $request, callable $next): Response
            {
                return Response::redirect('/login');
            }
        };

        $handlerCalled = false;
        $router->get('/secret', function (Request $r) use (&$handlerCalled): Response {
            $handlerCalled = true;

            return Response::text('secret');
        }, [$blocking]);

        $response = $router->dispatch(Request::create('GET', '/secret'));

        $this->assertFalse($handlerCalled);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function testMiddlewareCanRunAsObjectInstanceAndPassThrough(): void
    {
        $router = new Router();

        $passthrough = new class implements MiddlewareInterface {
            public function handle(Request $request, callable $next): Response
            {
                return $next($request->withAttribute('touched', true));
            }
        };

        $router->get('/x', function (Request $r): Response {
            return Response::text($r->getAttribute('touched') === true ? 'yes' : 'no');
        }, [$passthrough]);

        $response = $router->dispatch(Request::create('GET', '/x'));

        $this->assertSame('yes', $response->getBody());
    }

    public function testGlobalAndRouteMiddlewareCombineInOrder(): void
    {
        $router = new Router();
        $log = [];

        $router->pipe(static function (Request $r, callable $next) use (&$log): Response {
            $log[] = 'global';

            return $next($r);
        });

        $router->get('/x', static function (Request $r) use (&$log): Response {
            $log[] = 'handler';

            return Response::text('ok');
        }, [
            static function (Request $r, callable $next) use (&$log): Response {
                $log[] = 'route';

                return $next($r);
            },
        ]);

        $router->dispatch(Request::create('GET', '/x'));

        $this->assertSame(['global', 'route', 'handler'], $log);
    }
}
