<?php

declare(strict_types=1);

namespace N3\Core\Config;

use RuntimeException;

final class Environment
{
    public static function string(string $key, ?string $default = null): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null || trim((string) $value) === '') {
            if ($default !== null) {
                return $default;
            }

            throw new RuntimeException(sprintf('Required environment variable %s is missing.', $key));
        }

        return trim((string) $value);
    }

    public static function boolean(string $key, bool $default): bool
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null || trim((string) $value) === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw new RuntimeException(sprintf('%s must be true or false.', $key));
        }

        return $parsed;
    }

    /**
     * @param list<string> $allowed
     */
    public static function oneOf(string $key, array $allowed, string $default): string
    {
        $value = self::string($key, $default);

        if (!in_array($value, $allowed, true)) {
            throw new RuntimeException(
                sprintf('%s must be one of: %s.', $key, implode(', ', $allowed)),
            );
        }

        return $value;
    }
}
