<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Event\EventDispatcher;
use N3\Core\Module\ModuleManager;
use N3\Core\Module\ModuleMigrationProvider;
use N3\Core\Observability\RequestMetricsSink;
use N3\Core\Service\ServiceRegistry;
use N3\Module\Analytics\AnalyticsModule;
use N3\Module\Analytics\AnalyticsSchema;
use PHPUnit\Framework\TestCase;

final class AnalyticsModuleTest extends TestCase
{
    public function testModuleRegistersALazySinkAndOwnsANamedMigration(): void
    {
        $services = new ServiceRegistry();
        $module = new AnalyticsModule();
        (new ModuleManager('0.2.0', $services, new EventDispatcher()))->boot([$module]);

        self::assertSame(AnalyticsSchema::MODULE_ID, $module->manifest()->id);
        self::assertTrue($services->has(RequestMetricsSink::class));
        self::assertInstanceOf(ModuleMigrationProvider::class, $module);
        self::assertSame(AnalyticsSchema::MODULE_ID, $module->migrations()[0]->moduleId());
        self::assertSame('202609010001_create_hourly_metrics', $module->migrations()[0]->version());
    }

    public function testAnalyticsIsDisabledByDefaultAndCanBeExplicitlyEnabled(): void
    {
        $previous = getenv('ANALYTICS_ENABLED');
        $previousEnv = $_ENV['ANALYTICS_ENABLED'] ?? null;
        unset($_ENV['ANALYTICS_ENABLED']);
        putenv('ANALYTICS_ENABLED');

        try {
            $disabled = require dirname(__DIR__, 2) . '/config/modules.php';
            self::assertNotContains(AnalyticsSchema::MODULE_ID, array_map(
                static fn ($module): string => $module->manifest()->id,
                $disabled,
            ));

            putenv('ANALYTICS_ENABLED=true');
            $enabled = require dirname(__DIR__, 2) . '/config/modules.php';
            self::assertContains(AnalyticsSchema::MODULE_ID, array_map(
                static fn ($module): string => $module->manifest()->id,
                $enabled,
            ));
        } finally {
            if ($previous === false) {
                putenv('ANALYTICS_ENABLED');
            } else {
                putenv('ANALYTICS_ENABLED=' . $previous);
            }
            if ($previousEnv === null) {
                unset($_ENV['ANALYTICS_ENABLED']);
            } else {
                $_ENV['ANALYTICS_ENABLED'] = $previousEnv;
            }
        }
    }
}
