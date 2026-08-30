<?php

declare(strict_types=1);

namespace N3\Core\Module;

use DateTimeImmutable;
use LogicException;
use N3\Core\Event\CoreStarted;
use N3\Core\Event\EventDispatcher;
use N3\Core\Service\ServiceRegistry;
use Throwable;

final class ModuleManager
{
    /** @var list<string> */
    private array $bootOrder = [];

    private bool $booted = false;

    public function __construct(
        private readonly string $coreVersion,
        private readonly ServiceRegistry $services,
        private readonly EventDispatcher $events,
    ) {
        VersionConstraint::assertVersion($coreVersion);
    }

    /** @param list<Module> $modules */
    public function boot(array $modules): void
    {
        if ($this->booted) {
            throw new LogicException('Modules can only be booted once.');
        }

        $indexed = $this->indexAndValidate($modules);
        $ordered = $this->resolveOrder($indexed);

        foreach ($ordered as $module) {
            $this->runPhase($module, 'register', fn () => $module->register($this->services));
        }

        $this->services->freeze();

        foreach ($ordered as $module) {
            $this->runPhase($module, 'boot', fn () => $module->boot($this->services, $this->events));
            $this->bootOrder[] = $module->manifest()->id;
        }

        $this->events->seal();
        $this->events->dispatch(new CoreStarted($this->coreVersion, new DateTimeImmutable('now')));
        $this->booted = true;
    }

    /** @return list<string> */
    public function bootOrder(): array
    {
        return $this->bootOrder;
    }

    /**
     * @param list<Module> $modules
     * @return array<string, Module>
     */
    private function indexAndValidate(array $modules): array
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

        return $indexed;
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

    private function runPhase(Module $module, string $phase, callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            throw new ModuleLifecycleFailed(
                $module->manifest()->id,
                $phase,
                'module code threw an exception',
                $exception,
            );
        }
    }
}
