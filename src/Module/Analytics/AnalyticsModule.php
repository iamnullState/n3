<?php

declare(strict_types=1);

namespace N3\Module\Analytics;

use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Event\EventListenerRegistry;
use N3\Core\Http\Router;
use N3\Core\Logging\FileLogger;
use N3\Core\Module\Module;
use N3\Core\Module\ModuleManifest;
use N3\Core\Module\ModuleMigrationProvider;
use N3\Core\Observability\RequestMetricsSink;
use N3\Core\Security\CurrentPrincipalProvider;
use N3\Core\Service\ServiceRegistry;
use N3\Core\View\View;
use LogicException;
use N3\Module\Analytics\Migration\CreateHourlyMetrics;

final class AnalyticsModule implements Module, ModuleMigrationProvider
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(AnalyticsSchema::MODULE_ID, '0.2.0', '^0.2');
    }

    public function register(ServiceRegistry $services): void
    {
        $services->register(RequestMetricsSink::class, new LazyAnalyticsSink(
            static fn (): RequestMetricsSink => new PdoAnalyticsRepository(
                (new ConnectionFactory())->create(DatabaseConfig::fromEnvironment()),
            ),
        ));

        $router = $services->get(Router::class);
        $view = $services->get(View::class);
        $principals = $services->get(CurrentPrincipalProvider::class);
        $logger = $services->get(FileLogger::class);
        if (!$router instanceof Router || !$view instanceof View
            || !$principals instanceof CurrentPrincipalProvider || !$logger instanceof FileLogger) {
            throw new LogicException('Analytics dependencies do not satisfy their declared contracts.');
        }

        $reports = new LazyAnalyticsReportReader(
            static fn (): AnalyticsReportReader => new PdoAnalyticsRepository(
                (new ConnectionFactory())->create(DatabaseConfig::fromEnvironment()),
            ),
        );
        $controller = new AnalyticsController(
            $view,
            $principals,
            $reports,
            new AnalyticsVitals(dirname(__DIR__, 3)),
            $logger,
        );
        $router->get('/admin/analytics', [$controller, 'index']);
    }

    public function boot(ServiceRegistry $services, EventListenerRegistry $events): void
    {
    }

    public function migrations(): array
    {
        return [new CreateHourlyMetrics()];
    }
}
