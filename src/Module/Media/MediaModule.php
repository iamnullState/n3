<?php

declare(strict_types=1);

namespace N3\Module\Media;

use LogicException;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\TransactionManager;
use N3\Core\Event\EventListenerRegistry;
use N3\Core\Http\Router;
use N3\Core\Logging\FileLogger;
use N3\Core\Module\Module;
use N3\Core\Module\ModuleManifest;
use N3\Core\Module\ModuleMigrationProvider;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Security\CurrentPrincipalProvider;
use N3\Core\Service\ServiceRegistry;
use N3\Core\Session\FlashBag;
use N3\Core\Session\NativeSessionStore;
use N3\Core\Storage\ScopedModuleStorage;
use N3\Core\View\View;
use N3\Module\Media\Migration\CreateMediaLibrary;

final class MediaModule implements Module, ModuleMigrationProvider
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(MediaSchema::MODULE_ID, '0.1.0', '^0.2');
    }

    public function register(ServiceRegistry $services): void
    {
        $router = $services->get(Router::class);
        $view = $services->get(View::class);
        $principals = $services->get(CurrentPrincipalProvider::class);
        $logger = $services->get(FileLogger::class);
        if (!$router instanceof Router || !$view instanceof View
            || !$principals instanceof CurrentPrincipalProvider || !$logger instanceof FileLogger) {
            throw new LogicException('Media dependencies do not satisfy their declared contracts.');
        }

        $root = dirname(__DIR__, 3);
        /** @var array{environment: string} $app */
        $app = require $root . '/config/app.php';
        $config = MediaConfig::fromEnvironment();
        $processor = new GdImageProcessor($config);
        $repository = new LazyMediaRepository(static function () use ($config): MediaRepository {
            $connection = (new ConnectionFactory())->create(DatabaseConfig::fromEnvironment());
            return new PdoMediaRepository($connection, new TransactionManager($connection), $config->securityHashKey);
        });
        $media = new MediaService(
            $repository,
            $processor,
            new ScopedModuleStorage($root . '/storage/modules', MediaSchema::MODULE_ID, 'data', $config->maximumProcessedBytes),
            new ScopedModuleStorage($root . '/storage/modules', MediaSchema::MODULE_ID, 'cache'),
            $config,
        );
        $session = new NativeSessionStore($root . '/storage/sessions', $app['environment'] === 'production');
        $controller = new MediaController(
            $view,
            $principals,
            $media,
            new CsrfTokenManager($session),
            new FlashBag($session),
            $logger,
        );
        $services->register(MediaService::class, $media);
        $router->get('/admin/media', [$controller, 'index']);
        $router->post('/admin/media', [$controller, 'upload']);
        $router->get('/admin/media/{id}/preview', [$controller, 'preview']);
    }

    public function boot(ServiceRegistry $services, EventListenerRegistry $events): void
    {
    }

    public function migrations(): array
    {
        return [new CreateMediaLibrary()];
    }
}
