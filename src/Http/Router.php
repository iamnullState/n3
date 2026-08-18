<?php
declare(strict_types=1);

namespace N3\Http;

final class Router
{
    public function __construct(private readonly Request $request) {}

    public function matches(string $method, string $pattern): ?array
    {
        if ($method !== 'ANY' && $this->request->method() !== $method) return null;

        $pathSegments = $this->segments($this->request->path());
        $patternSegments = $this->segments($pattern);
        if (count($pathSegments) !== count($patternSegments)) return null;

        $parameters = [];
        foreach ($patternSegments as $index => $segment) {
            if (!preg_match('/^\{([a-z][a-z0-9_]*)\}$/D', $segment, $match)) {
                if ($segment !== $pathSegments[$index]) return null;
                continue;
            }

            $name = $match[1];
            $value = rawurldecode($pathSegments[$index]);
            if (in_array($name, ['id', 'revision'], true) && !ctype_digit($value)) return null;
            if ($name === 'slug' && !preg_match('/^[a-z0-9-]+$/D', $value)) return null;
            $parameters[$name] = in_array($name, ['id', 'revision'], true) ? (int)$value : $value;
        }
        return $parameters;
    }

    private function segments(string $value): array
    {
        if ($value === '/') return [];
        return explode('/', trim($value, '/'));
    }
}

