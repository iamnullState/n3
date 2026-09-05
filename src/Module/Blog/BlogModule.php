<?php

declare(strict_types=1);

namespace N3\Module\Blog;

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
use N3\Core\Security\CurrentActorProvider;
use N3\Core\Service\ServiceRegistry;
use N3\Core\Session\FlashBag;
use N3\Core\Session\NativeSessionStore;
use N3\Core\View\View;
use N3\Module\Blog\Migration\CreateBlogContent;

final class BlogModule implements Module, ModuleMigrationProvider
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(BlogSchema::MODULE_ID, '0.1.0', '^0.2');
    }

    public function register(ServiceRegistry $services): void
    {
        $router = $services->get(Router::class);
        $view = $services->get(View::class);
        $actors = $services->get(CurrentActorProvider::class);
        $logger = $services->get(FileLogger::class);
        if (!$router instanceof Router || !$view instanceof View
            || !$actors instanceof CurrentActorProvider || !$logger instanceof FileLogger) {
            throw new LogicException('Blog dependencies do not satisfy their declared contracts.');
        }

        $root = dirname(__DIR__, 3);
        /** @var array{environment: string} $app */
        $app = require $root . '/config/app.php';
        $repository = new LazyBlogRepository(static function (): BlogRepository {
            $connection = (new ConnectionFactory())->create(DatabaseConfig::fromEnvironment());

            return new PdoBlogRepository($connection, new TransactionManager($connection));
        });
        $blog = new BlogService(new BlogValidator(), $repository);
        $session = new NativeSessionStore($root . '/storage/sessions', $app['environment'] === 'production');
        $controller = new BlogController(
            $view,
            $actors,
            $blog,
            new CsrfTokenManager($session),
            new FlashBag($session),
            $logger,
        );
        $services->register(BlogService::class, $blog);
        $router->get('/admin/blog', [$controller, 'adminIndex']);
        $router->get('/admin/blog/create', [$controller, 'create']);
        $router->post('/admin/blog', [$controller, 'store']);
        $router->get('/admin/blog/{id}/edit', [$controller, 'edit']);
        $router->post('/admin/blog/{id}', [$controller, 'update']);
        $router->get('/admin/blog/{id}/preview', [$controller, 'preview']);
        $router->post('/admin/blog/{id}/publish', [$controller, 'publish']);
        $router->post('/admin/blog/{id}/unpublish', [$controller, 'unpublish']);
        $router->get('/blog', [$controller, 'publicIndex']);
        $router->get('/blog/{slug}', [$controller, 'publicPost']);
    }

    public function boot(ServiceRegistry $services, EventListenerRegistry $events): void
    {
    }

    public function migrations(): array
    {
        return [new CreateBlogContent()];
    }
}
