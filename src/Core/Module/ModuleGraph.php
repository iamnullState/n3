<?php

declare(strict_types=1);

namespace N3\Core\Module;

final readonly class ModuleGraph
{
    public function __construct(private string $coreVersion)
    {
        VersionConstraint::assertVersion($coreVersion);
    }

    /**
     * @param list<Module> $modules
     * @return list<Module>
     */
    public function ordered(array $modules): array
    {
        $indexed = [];

        foreach ($modules as $module) {
            $manifest = $module->manifest();

            if (isset($indexed[$manifest->id])) {
                throw new ModuleLifecycleFailed($manifest->id, 'validation', 'duplicate module ID');
            }
            if (!VersionConstraint::matches($this->coreVersion, $manifest->coreConstraint)) {
                throw new ModuleLifecycleFailed($manifest->id, 'validation', sprintf(
                    'Core %s does not satisfy %s',
                    $this->coreVersion,
                    $manifest->coreConstraint,
                ));
            }

            $indexed[$manifest->id] = $module;
        }

        foreach ($indexed as $id => $module) {
            $manifest = $module->manifest();

            foreach ($manifest->dependencies as $dependency => $constraint) {
                if (!isset($indexed[$dependency])) {
                    throw new ModuleLifecycleFailed($id, 'validation', sprintf('required module "%s" is not enabled', $dependency));
                }
                if (!VersionConstraint::matches($indexed[$dependency]->manifest()->version, $constraint)) {
                    throw new ModuleLifecycleFailed($id, 'validation', sprintf(
                        'module "%s" does not satisfy %s',
                        $dependency,
                        $constraint,
                    ));
                }
            }

            foreach ($manifest->conflicts as $conflict) {
                if (isset($indexed[$conflict])) {
                    throw new ModuleLifecycleFailed($id, 'validation', sprintf('conflicts with enabled module "%s"', $conflict));
                }
            }
        }

        return $this->resolveOrder($indexed);
    }

    /**
     * @param array<string, Module> $modules
     * @return list<Module>
     */
    private function resolveOrder(array $modules): array
    {
        $resolved = [];
        $visiting = [];
        $visited = [];

        $visit = function (string $id) use (&$visit, &$resolved, &$visiting, &$visited, $modules): void {
            if (isset($visiting[$id])) {
                throw new ModuleLifecycleFailed($id, 'validation', 'dependency cycle detected');
            }
            if (isset($visited[$id])) {
                return;
            }

            $visiting[$id] = true;
            foreach (array_keys($modules[$id]->manifest()->dependencies) as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$id]);
            $visited[$id] = true;
            $resolved[] = $modules[$id];
        };

        foreach (array_keys($modules) as $id) {
            $visit($id);
        }

        return $resolved;
    }
}
