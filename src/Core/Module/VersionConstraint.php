<?php

declare(strict_types=1);

namespace N3\Core\Module;

use InvalidArgumentException;

final class VersionConstraint
{
    public static function assertVersion(string $version): void
    {
        if (!preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
            throw new InvalidArgumentException(sprintf('Version "%s" must use numeric major.minor.patch format.', $version));
        }
    }

    public static function assertConstraint(string $constraint): void
    {
        if (!preg_match('/^(?:\^\d+\.\d+|\d+\.\d+\.\d+)$/D', $constraint)) {
            throw new InvalidArgumentException(sprintf(
                'Constraint "%s" must be an exact version or a supported caret major.minor constraint.',
                $constraint,
            ));
        }
    }

    public static function matches(string $version, string $constraint): bool
    {
        self::assertVersion($version);
        self::assertConstraint($constraint);

        if (!str_starts_with($constraint, '^')) {
            return $version === $constraint;
        }

        [$requiredMajor, $requiredMinor] = array_map('intval', explode('.', substr($constraint, 1)));
        [$major, $minor] = array_map('intval', array_slice(explode('.', $version), 0, 2));

        return $major === $requiredMajor && ($major !== 0 || $minor === $requiredMinor)
            && ($major === 0 || $minor >= $requiredMinor);
    }
}
