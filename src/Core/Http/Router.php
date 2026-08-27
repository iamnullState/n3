<?php

declare(strict_types=1);

namespace N3\Core\Http;

use Closure;
use RuntimeException;

final class Router
{
    /** @var array<string, Closure(Request): Response> */
    private array $routes = [];

    /**
     * @param callable(Request): Response $handler
     */
    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /**
     * @param callable(Request): Response $handler
     */
    public function add(string $method, string $path, callable $handler): void
    {
        $key = strtoupper($method) . ' ' . $this->normalizePath($path);
        $this->routes[$key] = Closure::fromCallable($handler);
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->method . ' ' . $request->path] ?? null;

        if ($handler === null) {
            throw new RouteNotFound();
        }

        $response = $handler($request);

        if (!$response instanceof Response) {
            throw new RuntimeException('Route handlers must return an HTTP response.');
        }

        return $response;
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? $path : rtrim($path, '/');
    }
}
