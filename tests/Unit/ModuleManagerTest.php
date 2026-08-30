<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use Closure;
use N3\Core\Event\EventDispatcher;
use N3\Core\Event\EventListenerRegistry;
use N3\Core\Module\Module;
use N3\Core\Module\ModuleLifecycleFailed;
use N3\Core\Module\ModuleManager;
use N3\Core\Module\ModuleManifest;
use N3\Core\Service\ServiceRegistry;
use N3\Module\CoreProbe\CoreProbeModule;
use N3\Module\CoreProbe\CoreProbeStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ModuleManagerTest extends TestCase
{
    public function testTheSampleModuleRegistersBootsAndObservesCoreStart(): void
    {
        $services = new ServiceRegistry();
        $manager = new ModuleManager('0.2.0', $services, new EventDispatcher());

        $manager->boot([new CoreProbeModule()]);

        $status = $services->get(CoreProbeStatus::class);
        self::assertInstanceOf(CoreProbeStatus::class, $status);
        self::assertTrue($status->booted);
        self::assertTrue($status->observedCoreStart);
        self::assertSame(['n3/core-probe'], $manager->bootOrder());
    }

    public function testDependenciesRegisterAndBootBeforeDependents(): void
    {
        $calls = [];
        $dependency = $this->module(
            new ModuleManifest('test/dependency', '1.2.0', '^0.2'),
            static function () use (&$calls): void {
                $calls[] = 'dependency-register';
            },
            static function () use (&$calls): void {
                $calls[] = 'dependency-boot';
            },
        );
        $consumer = $this->module(
            new ModuleManifest('test/consumer', '1.0.0', '^0.2', ['test/dependency' => '^1.2']),
            static function () use (&$calls): void {
                $calls[] = 'consumer-register';
            },
            static function () use (&$calls): void {
                $calls[] = 'consumer-boot';
            },
        );
        $manager = new ModuleManager('0.2.0', new ServiceRegistry(), new EventDispatcher());

        $manager->boot([$consumer, $dependency]);

        self::assertSame([
            'dependency-register',
            'consumer-register',
            'dependency-boot',
            'consumer-boot',
        ], $calls);
        self::assertSame(['test/dependency', 'test/consumer'], $manager->bootOrder());
    }

    public function testItRejectsMissingDependenciesBeforeModuleCodeRuns(): void
    {
        $module = $this->module(new ModuleManifest(
            'test/consumer',
            '1.0.0',
            '^0.2',
            ['test/missing' => '1.0.0'],
        ));

        $this->expectException(ModuleLifecycleFailed::class);
        $this->expectExceptionMessage('is not enabled');
        (new ModuleManager('0.2.0', new ServiceRegistry(), new EventDispatcher()))->boot([$module]);
    }

    public function testItRejectsDependencyCyclesAndConflicts(): void
    {
        $first = $this->module(new ModuleManifest(
            'test/first',
            '1.0.0',
            '^0.2',
            ['test/second' => '1.0.0'],
        ));
        $second = $this->module(new ModuleManifest(
            'test/second',
            '1.0.0',
            '^0.2',
            ['test/first' => '1.0.0'],
        ));

        try {
            (new ModuleManager('0.2.0', new ServiceRegistry(), new EventDispatcher()))->boot([$first, $second]);
            self::fail('A dependency cycle was accepted.');
        } catch (ModuleLifecycleFailed $exception) {
            self::assertStringContainsString('cycle', $exception->getMessage());
        }

        $conflicting = $this->module(new ModuleManifest(
            'test/conflicting',
            '1.0.0',
            '^0.2',
            conflicts: ['test/first'],
        ));

        $this->expectException(ModuleLifecycleFailed::class);
        $this->expectExceptionMessage('conflicts with');
        (new ModuleManager('0.2.0', new ServiceRegistry(), new EventDispatcher()))->boot([$first, $second, $conflicting]);
    }

    public function testLifecycleExceptionsAreAttributedAndFailClosed(): void
    {
        $module = $this->module(
            new ModuleManifest('test/failing', '1.0.0', '^0.2'),
            static function (): void {
                throw new RuntimeException('secret detail');
            },
        );

        try {
            (new ModuleManager('0.2.0', new ServiceRegistry(), new EventDispatcher()))->boot([$module]);
            self::fail('A failed registration did not stop startup.');
        } catch (ModuleLifecycleFailed $exception) {
            self::assertSame('test/failing', $exception->moduleId);
            self::assertSame('register', $exception->phase);
            self::assertStringNotContainsString('secret detail', $exception->getMessage());
        }
    }

    private function module(
        ModuleManifest $manifest,
        ?Closure $register = null,
        ?Closure $boot = null,
    ): Module {
        return new class ($manifest, $register, $boot) implements Module {
            public function __construct(
                private readonly ModuleManifest $definition,
                private readonly ?Closure $registerCallback,
                private readonly ?Closure $bootCallback,
            ) {
            }

            public function manifest(): ModuleManifest
            {
                return $this->definition;
            }

            public function register(ServiceRegistry $services): void
            {
                if ($this->registerCallback !== null) {
                    ($this->registerCallback)($services);
                }
            }

            public function boot(ServiceRegistry $services, EventListenerRegistry $events): void
            {
                if ($this->bootCallback !== null) {
                    ($this->bootCallback)($services, $events);
                }
            }
        };
    }
}
