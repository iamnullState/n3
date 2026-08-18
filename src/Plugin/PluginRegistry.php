<?php
declare(strict_types=1);

namespace N3\Plugin;

use N3\Http\Request;
use N3\Http\Response;

final class PluginRegistry
{
    private const MAX_ROUTE_SEGMENTS = 8;
    private const MAX_ROUTE_PARAMETERS = 6;

    private array $plugins = [];
    private array $routes = [];
    private array $contributions = [];
    private ?string $booting = null;
    private ?object $registration = null;
    private ?array $pendingPlugin = null;
    private array $pendingRoutes = [];
    private array $pendingContributionSlots = [];
    private array $pendingContributions = [];

    public function begin(array $plugin): object
    {
        if ($this->registration !== null) throw new \LogicException('A plugin registration is already in progress.');
        $this->booting = (string)$plugin['id'];
        $this->registration = new \stdClass();
        $this->pendingPlugin = $plugin;
        $this->pendingContributionSlots = array_fill_keys(
            is_array($plugin['contribution_slots'] ?? null) ? $plugin['contribution_slots'] : [],
            true,
        );
        unset($this->pendingPlugin['contribution_slots']);
        $this->pendingPlugin['dashboard'] = [];
        $this->pendingPlugin['navigation'] = [];
        $this->pendingRoutes = [];
        $this->pendingContributions = [];
        return $this->registration;
    }

    public function commit(object $registration): void
    {
        $this->assertRegistration($registration);
        $plugin = $this->pendingPlugin;
        if ($plugin === null) throw new \LogicException('Plugin registration metadata is missing.');
        $this->plugins[$plugin['id']] = $plugin;
        foreach ($this->pendingRoutes as $key => $route) $this->routes[$key] = $route;
        foreach ($this->pendingContributions as $slot => $handlers) {
            foreach ($handlers as $handler) $this->contributions[$slot][] = $handler;
        }
        $this->clearRegistration();
    }

    public function discard(object $registration): void
    {
        $this->assertRegistration($registration);
        $this->clearRegistration();
    }

    public function dashboardWidget(array $widget): void
    {
        if ($this->booting === null) throw new \LogicException('Dashboard widgets must be registered while a plugin is booting.');
        $title = mb_substr(trim((string)($widget['title'] ?? 'Plugin')), 0, 80);
        $body = mb_substr(trim((string)($widget['body'] ?? '')), 0, 300);
        $url = trim((string)($widget['url'] ?? ''));
        if ($url !== '' && !preg_match('~^(?:https?://|/)~i', $url)) $url = '';
        $this->pendingPlugin['dashboard'][] = ['title' => $title, 'body' => $body, 'url' => $url];
    }

    public function navigationItem(array $item): void
    {
        if ($this->booting === null) throw new \LogicException('Navigation items must be registered while a plugin is booting.');
        if (count($this->pendingPlugin['navigation']) >= 5) {
            throw new PluginRegistrationException('Plugin navigation item limit is exceeded.');
        }
        $label = $this->contributionText($item['label'] ?? '', 40);
        $icon = $this->contributionText($item['icon'] ?? '', 8);
        $url = $this->contributionUrl($item['url'] ?? null, $this->booting);
        if ($label === '' || $url === null) throw new PluginRegistrationException('Plugin navigation item is invalid.');
        $this->pendingPlugin['navigation'][] = ['label' => $label, 'url' => $url, 'icon' => $icon];
    }

    public function route(string $method, string $path, callable $handler): void
    {
        if ($this->booting === null) throw new \LogicException('Routes must be registered while a plugin is booting.');
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new PluginRegistrationException('Plugin route method is not supported.');
        }
        $namespace = '/api/plugins/' . $this->booting;
        if ($path !== $namespace && !str_starts_with($path, $namespace . '/')) {
            throw new PluginRegistrationException('Plugin route is outside its own API namespace.');
        }
        [$segments, $signature, $specificity] = $this->compileRoute($path);
        $key = $method . ' ' . $signature;
        if (array_key_exists($key, $this->pendingRoutes) || array_key_exists($key, $this->routes)) {
            throw new PluginRegistrationException('Plugin route duplicates an existing method and path.');
        }
        $this->pendingRoutes[$key] = [
            'plugin' => $this->booting,
            'method' => $method,
            'segments' => $segments,
            'specificity' => $specificity,
            'handler' => $handler,
        ];
    }

    public function profileTool(callable $handler): void
    {
        $this->contribution('profile_tools', $handler);
    }

    public function profileCard(callable $handler): void
    {
        $this->contribution('profile_cards', $handler);
    }

    public function pageInformationRow(callable $handler): void
    {
        $this->contribution('page_information', $handler);
    }

    public function profileTools(array $context): array
    {
        return $this->resolveContributions('profile_tools', $context);
    }

    public function profileCards(array $context): array
    {
        return $this->resolveContributions('profile_cards', $context);
    }

    public function pageInformationRows(array $context): array
    {
        return $this->resolveContributions('page_information', $context);
    }

    public function plugins(): array
    {
        return array_values($this->plugins);
    }

    public function dispatch(Request $request, array $user): ?Response
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
        $result = ($match['route']['handler'])($request->withRouteParams($match['parameters']), $user);
        if ($result instanceof Response) return $result;
        if (is_array($result)) return Response::json($result);
        return new Response((string)$result, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function compileRoute(string $path): array
    {
        $parts = explode('/', substr($path, 1));
        if (count($parts) < 3 || count($parts) > 3 + self::MAX_ROUTE_SEGMENTS) {
            throw new PluginRegistrationException('Plugin route path is invalid.');
        }
        $segments = [];
        $signature = [];
        $parameterNames = [];
        $specificity = 0;
        foreach ($parts as $index => $part) {
            if (preg_match('/^\{([a-z][a-z0-9_]{0,31})\}$/D', $part, $matches)) {
                if ($index < 3 || isset($parameterNames[$matches[1]])) {
                    throw new PluginRegistrationException('Plugin route path is invalid.');
                }
                $parameterNames[$matches[1]] = true;
                if (count($parameterNames) > self::MAX_ROUTE_PARAMETERS) {
                    throw new PluginRegistrationException('Plugin route path is invalid.');
                }
                $segments[] = ['parameter' => $matches[1]];
                $signature[] = '{}';
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._~-]{0,127}$/D', $part)) {
                throw new PluginRegistrationException('Plugin route path is invalid.');
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
        if (count($parts) < 3 || count($parts) > 3 + self::MAX_ROUTE_SEGMENTS) return null;
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
                continue;
            }
            $parameters[$segment['parameter']] = $requestSegments[$index];
        }
        return $parameters;
    }

    private function assertRegistration(object $registration): void
    {
        if ($this->registration === null || $this->registration !== $registration) {
            throw new \LogicException('Plugin registration token is invalid.');
        }
    }

    private function clearRegistration(): void
    {
        $this->booting = null;
        $this->registration = null;
        $this->pendingPlugin = null;
        $this->pendingRoutes = [];
        $this->pendingContributionSlots = [];
        $this->pendingContributions = [];
    }

    private function contribution(string $slot, callable $handler): void
    {
        if ($this->booting === null) throw new \LogicException('Contributions must be registered while a plugin is booting.');
        if (!isset($this->pendingContributionSlots[$slot])) {
            throw new PluginRegistrationException('Plugin contribution slot is not declared in its manifest.');
        }
        if (count($this->pendingContributions[$slot] ?? []) >= 10) {
            throw new PluginRegistrationException('Plugin contribution slot exceeds its registration limit.');
        }
        $this->pendingContributions[$slot][] = [
            'plugin_id' => $this->booting,
            'plugin_name' => (string)($this->pendingPlugin['name'] ?? $this->booting),
            'handler' => $handler,
        ];
    }

    private function resolveContributions(string $slot, array $context): array
    {
        $resolved = [];
        foreach ($this->contributions[$slot] ?? [] as $contribution) {
            try {
                $result = ($contribution['handler'])($context);
                if (!is_array($result)) continue;
                $items = array_is_list($result) ? array_slice($result, 0, 10) : [$result];
                foreach ($items as $item) {
                    if (!is_array($item)) continue;
                    $normalized = $this->normalizeContribution($slot, $item, (string)$contribution['plugin_id']);
                    if ($normalized === null) continue;
                    $resolved[] = $normalized + [
                        'plugin_id' => (string)$contribution['plugin_id'],
                        'plugin_name' => (string)$contribution['plugin_name'],
                    ];
                }
            } catch (\Throwable) {
                error_log('Plugin ' . $contribution['plugin_id'] . ' contribution failed for slot ' . $slot . '.');
            }
        }
        return $resolved;
    }

    private function normalizeContribution(string $slot, array $item, string $pluginId): ?array
    {
        if ($slot === 'page_information') {
            $label = $this->contributionText($item['label'] ?? '', 40);
            $value = $this->contributionText($item['value'] ?? '', 160);
            return $label === '' || $value === '' ? null : ['label' => $label, 'value' => $value];
        }
        $url = $this->contributionUrl($item['url'] ?? null, $pluginId);
        if ($slot === 'profile_tools') {
            $label = $this->contributionText($item['label'] ?? '', 80);
            return $label === '' || $url === null ? null : ['label' => $label, 'url' => $url];
        }
        $title = $this->contributionText($item['title'] ?? '', 80);
        $body = $this->contributionText($item['body'] ?? '', 300);
        if ($title === '' || $body === '') return null;
        return ['title' => $title, 'body' => $body, 'url' => $url];
    }

    private function contributionText(mixed $value, int $limit): string
    {
        if (!is_string($value)) return '';
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        return mb_substr(trim($value), 0, $limit);
    }

    private function contributionUrl(mixed $value, string $pluginId): ?string
    {
        if (!is_string($value)) return null;
        $url = trim($value);
        $namespace = '/api/plugins/' . $pluginId;
        if ($url !== $namespace && !str_starts_with($url, $namespace . '/') && !str_starts_with($url, $namespace . '?')) return null;
        return mb_strlen($url) <= 500 ? $url : null;
    }
}
