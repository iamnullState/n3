<?php

declare(strict_types=1);

namespace N3\Core\Http;

use Closure;
use RuntimeException;

final class Router
{
    /** @var array<string, Closure(Request): Response> */
    private array $routes = [];

    /** @var list<array{method: string, pattern: string, handler: Closure(Request): Response}> */
    private array $dynamicRoutes = [];

    /**
     * @param callable(Request): Response $handler
     */
    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /** @param callable(Request): Response $handler */
    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /**
     * @param callable(Request): Response $handler
     */
    public function add(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        $path = $this->normalizePath($path);
        $closure = Closure::fromCallable($handler);

        if (!str_contains($path, '{')) {
            $this->routes[$method . ' ' . $path] = $closure;
            return;
        }

        $this->dynamicRoutes[] = [
            'method' => $method,
            'pattern' => $this->compilePattern($path),
            'handler' => $closure,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->method . ' ' . $request->path] ?? null;

        if ($handler === null) {
            foreach ($this->dynamicRoutes as $route) {
                if ($route['method'] !== $request->method || !preg_match($route['pattern'], $request->path, $matches)) {
                    continue;
                }
                $parameters = [];
                foreach ($matches as $name => $value) {
                    if (is_string($name)) {
                        $parameters[$name] = rawurldecode((string) $value);
                    }
                }
                $request = $request->withAttribute('route_parameters', $parameters);
                $handler = $route['handler'];
                break;
            }
        }

        if ($handler === null) {
            $suffix = ' ' . $request->path;
            $allowed = [];

            foreach (array_keys($this->routes) as $route) {
                if (str_ends_with($route, $suffix)) {
                    $allowed[] = strstr($route, ' ', true) ?: '';
                }
            }

            foreach ($this->dynamicRoutes as $route) {
                if (preg_match($route['pattern'], $request->path)) {
                    $allowed[] = $route['method'];
                }
            }

            if ($allowed !== []) {
                sort($allowed);
                throw new MethodNotAllowed(array_values(array_unique($allowed)));
            }

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

    private function compilePattern(string $path): string
    {
        if (!preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('Dynamic routes require a valid parameter placeholder.');
        }
        $pattern = '';
        $offset = 0;
        $names = [];
        foreach ($matches[0] as $index => [$placeholder, $position]) {
            $name = $matches[1][$index][0];
            if (in_array($name, $names, true)) {
                throw new RuntimeException('Dynamic route parameter names must be unique.');
            }
            $names[] = $name;
            $pattern .= preg_quote(substr($path, $offset, $position - $offset), '#');
            $pattern .= '(?P<' . $name . '>[^/]+)';
            $offset = $position + strlen($placeholder);
        }
        $pattern .= preg_quote(substr($path, $offset), '#');

        return '#^' . $pattern . '$#D';
    }
}
