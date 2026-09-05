<?php

declare(strict_types=1);

namespace N3\App\Install;

use N3\App\Controller\InstallerController;
use N3\App\Identity\PdoUserRepository;
use N3\Core\Application;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Http\Router;
use N3\Core\Logging\FileLogger;
use N3\Core\Module\ModuleGraph;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\FlashBag;
use N3\Core\Session\NativeSessionStore;
use N3\Core\View\View;
use Throwable;

final class InstallerKernel
{
    public static function application(
        string $root,
        Request $request,
        ?InstallationLock $lock = null,
    ): Application {
        $view = new View($root . '/resources/views');
        $logger = new FileLogger($root . '/storage/logs/app.log');
        $router = new Router();
        $environment = 'production';

        try {
            /** @var array{version: string, environment: string, timezone: string} $app */
            $app = require $root . '/config/app.php';
            $environment = $app['environment'];
            date_default_timezone_set($app['timezone']);
            $config = InstallerConfig::fromEnvironment($environment);
            $runtime = DatabaseConfig::fromEnvironment();
            $migration = DatabaseConfig::fromMigrationEnvironment();
            $connection = (new ConnectionFactory())->create($migration);
            $runtimeConnection = (new ConnectionFactory())->create($runtime);
            $modules = (new ModuleGraph($app['version']))->ordered(require $root . '/config/modules.php');
            $lock ??= new InstallationLock($root . '/storage/install/installed.lock');
            $state = new PdoInstallationStateRepository($connection);
            $service = new InstallerService(
                $root,
                $config,
                $runtime,
                $migration,
                $connection,
                $runtimeConnection,
                $state,
                new PdoUserRepository($connection),
                $modules,
                $lock,
            );
            $session = new NativeSessionStore(
                $root . '/storage/install/sessions',
                $environment === 'production',
                'n3_install',
            );
            $csrf = new CsrfTokenManager($session);
            $controller = new InstallerController(
                $view,
                $config,
                $service,
                $csrf,
                new FlashBag($session),
                $session,
                new InstallerAttemptLimiter($session),
                $logger,
            );

            $router->get('/install', [$controller, 'show']);
            $router->post('/install/authorize', [$controller, 'authorize']);
            $router->post('/install/migrate', [$controller, 'migrate']);
            $router->post('/install/admin', [$controller, 'createAdmin']);
            $router->post('/install/complete', [$controller, 'complete']);
        } catch (Throwable $exception) {
            $logger->error('installer_bootstrap_unavailable', ['exception' => $exception::class]);
            $unavailable = static fn (): Response => Response::html($view->render('install/unavailable', [
                'pageTitle' => 'Site setup unavailable',
                'metaDescription' => 'Site setup prerequisites are incomplete.',
                'robots' => 'noindex, nofollow',
            ], 'layouts/install'), 503)
                ->withHeader('Cache-Control', 'no-store, private')
                ->withHeader('X-Robots-Tag', 'noindex, nofollow');
            $router->get('/install', $unavailable);
        }

        if ($request->path !== '/install' && !str_starts_with($request->path, '/install/')) {
            $router->add($request->method, $request->path, static fn (): Response => Response::redirect('/install'));
        }

        return new Application($router, $view, $logger, $environment);
    }
}
