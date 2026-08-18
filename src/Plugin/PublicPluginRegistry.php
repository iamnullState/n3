<?php
declare(strict_types=1);

namespace N3\Plugin;

use N3\Http\Request;
use N3\Http\Response;

final class PublicPluginRegistry
{
    private const MAX_ROUTE_SEGMENTS = 8;
    private const MAX_ROUTE_PARAMETERS = 6;

    private array $routes = [];
    private ?string $booting = null;
    private array $prefixes = [];
    private ?object $registration = null;
    private array $pendingRoutes = [];

    public function begin(string $pluginId, array $prefixes): object
    {
        if ($this->registration !== null) throw new \LogicException('A public plugin registration is already in progress.');
        $this->booting = $pluginId;
        $this->prefixes = array_values($prefixes);
        $this->registration = new \stdClass();
        $this->pendingRoutes = [];
        return $this->registration;
    }

    public function commit(object $registration): void
    {
        $this->assertRegistration($registration);
        foreach ($this->pendingRoutes as $key => $route) $this->routes[$key] = $route;
        $this->clearRegistration();
    }

    public function discard(object $registration): void
    {
        $this->assertRegistration($registration);
        $this->clearRegistration();
    }

    public function route(string $method, string $path, callable $handler): void
    {
        if ($this->booting === null) throw new \LogicException('Public routes must be registered while a plugin is booting.');
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            throw new PluginRegistrationException('Public plugin route method is not supported.');
        }
        $owned = false;
        foreach ($this->prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $owned = true;
                break;
            }
        }
        if (!$owned) throw new PluginRegistrationException('Public plugin route is outside its declared prefix.');

        [$segments, $signature, $specificity] = $this->compileRoute($path);
        $key = $method . ' ' . $signature;
        if (isset($this->pendingRoutes[$key]) || isset($this->routes[$key])) {
            throw new PluginRegistrationException('Public plugin route duplicates an existing method and path.');
        }
        $this->pendingRoutes[$key] = [
            'plugin' => $this->booting,
            'method' => $method,
            'segments' => $segments,
            'specificity' => $specificity,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): ?Response
    {
        $requestSegments = $this->requestSegments($request->path());
        if ($requestSegments === null) return null;
        $match = null;
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) continue;
            $parameters = $this->matchRoute($route['segments'], $requestSegments);
            if ($parameters === null) continue;
            if ($match === null || $route['specificity'] > $match['route']['specificity']) {
                $match = ['route' => $route, 'parameters' => $parameters];
            }
        }
        if ($match === null) return null;
        $response = ($match['route']['handler'])($request->withRouteParams($match['parameters']));
        if (!$response instanceof Response) throw new \RuntimeException('A public plugin route must return a Response.');
        return $response;
    }

    private function compileRoute(string $path): array
    {
        $parts = explode('/', substr($path, 1));
        if ($parts === [] || count($parts) > 1 + self::MAX_ROUTE_SEGMENTS) {
            throw new PluginRegistrationException('Public plugin route path is invalid.');
        }
        $segments = [];
        $signature = [];
        $parameterNames = [];
        $specificity = 0;
        foreach ($parts as $part) {
            if (preg_match('/^\{([a-z][a-z0-9_]{0,31})\}$/D', $part, $matches)) {
                if (isset($parameterNames[$matches[1]]) || count($parameterNames) >= self::MAX_ROUTE_PARAMETERS) {
                    throw new PluginRegistrationException('Public plugin route path is invalid.');
                }
                $parameterNames[$matches[1]] = true;
                $segments[] = ['parameter' => $matches[1]];
                $signature[] = '{}';
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._~-]{0,127}$/D', $part)) {
                throw new PluginRegistrationException('Public plugin route path is invalid.');
            }
            $segments[] = ['literal' => $part];
            $signature[] = $part;
            $specificity++;
        }
        return [$segments, '/' . implode('/', $signature), $specificity];
    }

    private function requestSegments(string $path): ?array
    {
        if ($path === '' || $path[0] !== '/') return null;
        $parts = explode('/', substr($path, 1));
        if ($parts === [] || count($parts) > 1 + self::MAX_ROUTE_SEGMENTS) return null;
        $segments = [];
        foreach ($parts as $part) {
            if ($part === '' || preg_match('/%(?![0-9a-f]{2})/i', $part)) return null;
            $decoded = rawurldecode($part);
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._~-]{0,127}$/D', $decoded)) return null;
            $segments[] = $decoded;
        }
        return $segments;
    }

    private function matchRoute(array $routeSegments, array $requestSegments): ?array
    {
        if (count($routeSegments) !== count($requestSegments)) return null;
        $parameters = [];
        foreach ($routeSegments as $index => $segment) {
            if (isset($segment['literal'])) {
                if ($segment['literal'] !== $requestSegments[$index]) return null;
            } else {
                $parameters[$segment['parameter']] = $requestSegments[$index];
            }
        }
        return $parameters;
    }

    private function assertRegistration(object $registration): void
    {
        if ($this->registration === null || $this->registration !== $registration) {
            throw new \LogicException('Public plugin registration token is invalid.');
        }
    }

    private function clearRegistration(): void
    {
        $this->booting = null;
        $this->prefixes = [];
        $this->registration = null;
        $this->pendingRoutes = [];
    }
}
