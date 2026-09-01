<?php

declare(strict_types=1);

namespace N3\Core\Module;

use InvalidArgumentException;

final class ModuleResourcePolicy
{
    /** @return array{string, string} */
    public static function segments(string $moduleId): array
    {
        if (!preg_match('/^([a-z0-9][a-z0-9.-]*)\/([a-z0-9][a-z0-9.-]*)$/D', $moduleId, $matches)) {
            throw new InvalidArgumentException('Module resource IDs must use lowercase vendor/name format.');
        }

        return [$matches[1], $matches[2]];
    }

    /** @return list<string> */
    public static function relativeSegments(string $relativePath): array
    {
        if ($relativePath === '' || strlen($relativePath) > 255 || str_contains($relativePath, "\0") || str_contains($relativePath, '\\')) {
            throw new InvalidArgumentException('Module resource paths are invalid.');
        }

        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 100
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $segment)) {
                throw new InvalidArgumentException('Module resource paths are invalid.');
            }
        }

        return $segments;
    }

    public static function schemaPrefix(string $moduleId): string
    {
        [$vendor, $name] = self::segments($moduleId);
        $readable = str_replace(['.', '-'], '_', $vendor . '_' . $name);
        $readable = substr($readable, 0, 42);

        return 'm_' . $readable . '_' . substr(hash('sha256', $moduleId), 0, 8) . '_';
    }

    public static function configPrefix(string $moduleId): string
    {
        [$vendor, $name] = self::segments($moduleId);

        return 'modules.' . $vendor . '.' . $name . '.';
    }
}
