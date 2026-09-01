<?php

declare(strict_types=1);

namespace N3\Core\Module;

use N3\Core\Database\TransactionManager;
use RuntimeException;

final readonly class ModuleLifecycleService
{
    public function __construct(
        private ModuleLifecycleRepository $repository,
        private TransactionManager $transactions,
    ) {
    }

    /**
     * @param list<Module> $modules
     * @return list<ModuleChange>
     */
    public function plan(array $modules): array
    {
        $existing = $this->repository->all();
        $desired = [];
        $changes = [];

        foreach ($modules as $module) {
            $manifest = $module->manifest();
            $hash = self::manifestHash($manifest);
            $desired[$manifest->id] = true;
            $state = $existing[$manifest->id] ?? null;

            if ($state === null) {
                $changes[] = new ModuleChange('install', $manifest->id, null, $manifest->version, null, 'enabled', $hash);
                continue;
            }

            $comparison = version_compare($manifest->version, $state->version);
            if ($comparison < 0) {
                throw new RuntimeException(sprintf('Module "%s" cannot be downgraded from %s to %s.', $manifest->id, $state->version, $manifest->version));
            }
            if ($comparison === 0 && !hash_equals($state->manifestHash, $hash)) {
                throw new RuntimeException(sprintf('Module "%s" changed its manifest without a version change.', $manifest->id));
            }
            if ($comparison > 0) {
                $changes[] = new ModuleChange('update', $manifest->id, $state->version, $manifest->version, $state->state, $state->state, $hash);
            }
            if ($state->state === 'disabled') {
                $changes[] = new ModuleChange('enable', $manifest->id, $manifest->version, $manifest->version, 'disabled', 'enabled');
            }
        }

        foreach ($existing as $state) {
            if ($state->state === 'enabled' && !isset($desired[$state->id])) {
                $changes[] = new ModuleChange('disable', $state->id, $state->version, $state->version, 'enabled', 'disabled');
            }
        }

        return $changes;
    }

    /** @param list<ModuleChange> $changes */
    public function apply(array $changes): void
    {
        $this->transactions->run(function () use ($changes): void {
            foreach ($changes as $change) {
                $this->repository->apply($change);
            }
        });
    }

    public static function manifestHash(ModuleManifest $manifest): string
    {
        $dependencies = $manifest->dependencies;
        $conflicts = $manifest->conflicts;
        ksort($dependencies, SORT_STRING);
        sort($conflicts, SORT_STRING);

        return hash('sha256', json_encode([
            'id' => $manifest->id,
            'version' => $manifest->version,
            'core' => $manifest->coreConstraint,
            'dependencies' => $dependencies,
            'conflicts' => $conflicts,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
