<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Event\EventDispatcher;
use N3\Core\Module\ModuleManager;
use N3\Core\Module\ModuleMigrationProvider;
use N3\Core\Http\Request;
use N3\Core\Http\Router;
use N3\Core\Logging\FileLogger;
use N3\Core\Observability\RequestMetricsSink;
use N3\Core\Security\CurrentPrincipal;
use N3\Core\Security\CurrentPrincipalProvider;
use N3\Core\Service\ServiceRegistry;
use N3\Core\View\View;
use N3\Module\Analytics\AnalyticsModule;
use N3\Module\Analytics\AnalyticsSchema;
use PHPUnit\Framework\TestCase;

final class AnalyticsModuleTest extends TestCase
{
    public function testModuleRegistersALazySinkAndOwnsANamedMigration(): void
    {
        $services = new ServiceRegistry();
        $router = new Router();
        $log = tempnam(sys_get_temp_dir(), 'n3-analytics-module-');
        self::assertNotFalse($log);
        $services->register(Router::class, $router);
        $services->register(View::class, new View(dirname(__DIR__, 2) . '/resources/views'));
        $services->register(FileLogger::class, new FileLogger($log));
        $services->register(CurrentPrincipalProvider::class, new class implements CurrentPrincipalProvider {
            public function current(): ?CurrentPrincipal { return null; }
        });
        $module = new AnalyticsModule();
        try {
            (new ModuleManager('0.2.0', $services, new EventDispatcher()))->boot([$module]);

            self::assertSame(AnalyticsSchema::MODULE_ID, $module->manifest()->id);
            self::assertSame('0.2.0', $module->manifest()->version);
            self::assertTrue($services->has(RequestMetricsSink::class));
            self::assertInstanceOf(ModuleMigrationProvider::class, $module);
            self::assertSame(AnalyticsSchema::MODULE_ID, $module->migrations()[0]->moduleId());
            self::assertSame('202609010001_create_hourly_metrics', $module->migrations()[0]->version());
            $response = $router->dispatch(Request::create('GET', '/admin/analytics'));
            self::assertSame(303, $response->status());
            self::assertSame('/login', $response->headers()['Location']);
        } finally {
            unlink($log);
        }
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
