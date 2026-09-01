<?php

declare(strict_types=1);

namespace N3\Module\Analytics;

use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Event\EventListenerRegistry;
use N3\Core\Module\Module;
use N3\Core\Module\ModuleManifest;
use N3\Core\Module\ModuleMigrationProvider;
use N3\Core\Observability\RequestMetricsSink;
use N3\Core\Service\ServiceRegistry;
use N3\Module\Analytics\Migration\CreateHourlyMetrics;

final class AnalyticsModule implements Module, ModuleMigrationProvider
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(AnalyticsSchema::MODULE_ID, '0.1.0', '^0.2');
    }

    public function register(ServiceRegistry $services): void
    {
        $services->register(RequestMetricsSink::class, new LazyAnalyticsSink(
            static fn (): RequestMetricsSink => new PdoAnalyticsRepository(
                (new ConnectionFactory())->create(DatabaseConfig::fromEnvironment()),
            ),
        ));
    }

    public function boot(ServiceRegistry $services, EventListenerRegistry $events): void
    {
    }

    public function migrations(): array
    {
        return [new CreateHourlyMetrics()];
    }
}
