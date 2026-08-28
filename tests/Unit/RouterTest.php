<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Http\MethodNotAllowed;
use N3\Core\Http\RouteNotFound;
use N3\Core\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testItDispatchesAnExactRoute(): void
    {
        $router = new Router();
        $router->get('/', static fn (Request $request): Response => Response::html('home'));

        $response = $router->dispatch(Request::create('GET', '/'));

        self::assertSame(200, $response->status());
        self::assertSame('home', $response->body());
    }

    public function testItRejectsAnUnknownRoute(): void
    {
        $this->expectException(RouteNotFound::class);

        (new Router())->dispatch(Request::create('GET', '/missing'));
    }

    public function testItDispatchesPostAndReportsAllowedMethods(): void
    {
        $router = new Router();
        $router->post('/register', static fn (): Response => Response::html('created', 201));

        self::assertSame(201, $router->dispatch(Request::create('POST', '/register'))->status());

        try {
            $router->dispatch(Request::create('GET', '/register'));
            self::fail('A GET request reached a POST-only route.');
        } catch (MethodNotAllowed $exception) {
            self::assertSame(['POST'], $exception->allowed);
        }
    }
}
