<?php

declare(strict_types=1);

namespace N3\Core\Module;

use InvalidArgumentException;

final readonly class ModuleManifest
{
    /**
     * @param array<string, string> $dependencies Module ID => supported version constraint.
     * @param list<string> $conflicts
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $coreConstraint,
        public array $dependencies = [],
        public array $conflicts = [],
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9.-]*\/[a-z0-9][a-z0-9.-]*$/D', $id)) {
            throw new InvalidArgumentException('Module IDs must use a lowercase vendor/name format.');
        }

        VersionConstraint::assertVersion($version);
        VersionConstraint::assertConstraint($coreConstraint);

        foreach ($dependencies as $dependency => $constraint) {
            if (!is_string($dependency) || !preg_match('/^[a-z0-9][a-z0-9.-]*\/[a-z0-9][a-z0-9.-]*$/D', $dependency)) {
                throw new InvalidArgumentException('Module dependency IDs must use a lowercase vendor/name format.');
            }
            VersionConstraint::assertConstraint($constraint);
        }

        foreach ($conflicts as $conflict) {
            if (!is_string($conflict) || !preg_match('/^[a-z0-9][a-z0-9.-]*\/[a-z0-9][a-z0-9.-]*$/D', $conflict)) {
                throw new InvalidArgumentException('Module conflict IDs must use a lowercase vendor/name format.');
            }
        }
    }
}
