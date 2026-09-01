<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Database\TransactionManager;
use N3\Core\Event\EventListenerRegistry;
use N3\Core\Module\Module;
use N3\Core\Module\ModuleChange;
use N3\Core\Module\ModuleLifecycleRepository;
use N3\Core\Module\ModuleLifecycleService;
use N3\Core\Module\ModuleManifest;
use N3\Core\Module\ModuleState;
use N3\Core\Service\ServiceRegistry;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ModuleLifecycleServiceTest extends TestCase
{
    public function testItPlansInstallDisableAndEnableWithoutDeletingState(): void
    {
        $repository = new MemoryModuleRepository();
        $service = $this->service($repository);
        $module = new LifecycleTestModule(new ModuleManifest('test/example', '1.0.0', '^0.2'));

        $install = $service->plan([$module]);
        self::assertSame(['install'], array_column($install, 'action'));
        $service->apply($install);

        $disable = $service->plan([]);
        self::assertSame(['disable'], array_column($disable, 'action'));
        $service->apply($disable);
        self::assertSame('disabled', $repository->states['test/example']->state);

        $enable = $service->plan([$module]);
        self::assertSame(['enable'], array_column($enable, 'action'));
    }

    public function testItRequiresForwardVersionsAndStableSameVersionManifests(): void
    {
        $original = new ModuleManifest('test/example', '2.0.0', '^0.2');
        $repository = new MemoryModuleRepository([
            'test/example' => new ModuleState(
                'test/example',
                '2.0.0',
                ModuleLifecycleService::manifestHash($original),
                'enabled',
            ),
        ]);
        $service = $this->service($repository);

        try {
            $service->plan([new LifecycleTestModule(new ModuleManifest('test/example', '1.9.0', '^0.2'))]);
            self::fail('A module downgrade was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cannot be downgraded', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a version change');
        $service->plan([new LifecycleTestModule(new ModuleManifest(
            'test/example',
            '2.0.0',
            '^0.2',
            ['test/dependency' => '1.0.0'],
        ))]);
    }

    public function testItPlansAForwardUpdateAndAppliesAtomically(): void
    {
        $old = new ModuleManifest('test/example', '1.0.0', '^0.2');
        $repository = new MemoryModuleRepository([
            'test/example' => new ModuleState('test/example', '1.0.0', ModuleLifecycleService::manifestHash($old), 'enabled'),
        ]);
        $service = $this->service($repository);
        $new = new LifecycleTestModule(new ModuleManifest('test/example', '1.1.0', '^0.2'));

        $changes = $service->plan([$new]);
        $service->apply($changes);

        self::assertSame(['update'], array_column($changes, 'action'));
        self::assertSame('1.1.0', $repository->states['test/example']->version);
    }

    private function service(MemoryModuleRepository $repository): ModuleLifecycleService
    {
        return new ModuleLifecycleService($repository, new TransactionManager(new PDO('sqlite::memory:')));
    }
}

final class LifecycleTestModule implements Module
{
    public function __construct(private readonly ModuleManifest $definition)
    {
    }

    public function manifest(): ModuleManifest
    {
        return $this->definition;
    }

    public function register(ServiceRegistry $services): void
    {
    }

    public function boot(ServiceRegistry $services, EventListenerRegistry $events): void
    {
    }
}

final class MemoryModuleRepository implements ModuleLifecycleRepository
{
    /** @param array<string, ModuleState> $states */
    public function __construct(public array $states = [])
    {
    }

    public function all(): array
    {
        return $this->states;
    }

    public function apply(ModuleChange $change): void
    {
        $current = $this->states[$change->moduleId] ?? null;
        $this->states[$change->moduleId] = new ModuleState(
            $change->moduleId,
            $change->toVersion ?? $current?->version ?? '',
            $change->manifestHash ?? $current?->manifestHash ?? '',
            $change->toState ?? $current?->state ?? 'disabled',
        );
    }
}
