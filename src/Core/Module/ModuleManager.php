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

        $ordered = (new ModuleGraph($this->coreVersion))->ordered($modules);

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
