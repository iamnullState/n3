<?php

declare(strict_types=1);

namespace N3\App\Content;

use N3\App\Controller\AdminPageController;
use N3\App\Controller\PublicPageController;
use N3\App\Controller\HomeController;
use N3\App\Controller\SiteAdminController;
use N3\App\Controller\SitePublicController;
use N3\App\Identity\AuthSessionManager;
use N3\App\Identity\IdentityConfig;
use N3\App\Identity\PdoUserRepository;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\TransactionManager;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\FlashBag;
use N3\Core\Session\NativeSessionStore;
use N3\Core\View\View;
use N3\App\Site\PdoSiteRepository;
use N3\App\Site\SiteService;
use N3\App\Site\SiteValidator;

final class ContentKernel
{
    /** @param array{name: string, version: string, environment: string, debug: bool, timezone: string}|null $appConfig
     * @return array{public: PublicPageController, sitePublic: SitePublicController}
     */
    public static function publicControllers(
        string $root,
        View $view,
        string $environment,
        ?PageMediaProvider $media = null,
        ?array $appConfig = null,
    ): array {
        $database = require $root . '/config/database.php';
        $connection = (new ConnectionFactory())->create($database);
        $transactions = new TransactionManager($connection);
        $pages = new PageService(
            new PageValidator(),
            new PdoPageRepository($connection),
            new PdoContentEventRecorder($connection),
            $transactions,
        );
        $site = new SiteService(new PdoSiteRepository($connection, $transactions), new SiteValidator());
        $appConfig ??= ['name' => 'N3', 'version' => '0.2.0', 'environment' => $environment, 'debug' => false, 'timezone' => 'UTC'];

        return [
            'public' => new PublicPageController($view, $pages, $media, $site),
            'sitePublic' => new SitePublicController($view, $pages, $site, new HomeController($view, $appConfig), $media),
        ];
    }

    /** @param array{name: string, version: string, environment: string, debug: bool, timezone: string}|null $appConfig
     * @return array{admin: AdminPageController, public: PublicPageController, siteAdmin: SiteAdminController, sitePublic: SitePublicController}
     */
    public static function controllers(
        string $root,
        View $view,
        string $environment,
        ?PageMediaProvider $media = null,
        ?array $appConfig = null,
    ): array
    {
        $database = require $root . '/config/database.php';
        $connection = (new ConnectionFactory())->create($database);
        $config = IdentityConfig::fromEnvironment($environment);
        $session = new NativeSessionStore($root . '/storage/sessions', $environment === 'production');
        $csrf = new CsrfTokenManager($session);
        $users = new PdoUserRepository($connection);
        $service = new PageService(
            new PageValidator(),
            new PdoPageRepository($connection),
            new PdoContentEventRecorder($connection),
            new TransactionManager($connection),
        );
        $site = new SiteService(new PdoSiteRepository($connection, new TransactionManager($connection)), new SiteValidator());
        $auth = new AuthSessionManager($session, $csrf, $users, $config->sessionIdleTtl, $config->sessionAbsoluteTtl);
        $flash = new FlashBag($session);
        $appConfig ??= ['name' => 'N3', 'version' => '0.2.0', 'environment' => $environment, 'debug' => false, 'timezone' => 'UTC'];

        return [
            'admin' => new AdminPageController(
                $view,
                $service,
                $auth,
                $csrf,
                $flash,
                $media,
            ),
            'public' => new PublicPageController($view, $service, $media, $site),
            'siteAdmin' => new SiteAdminController($view, $site, $auth, $csrf, $flash),
            'sitePublic' => new SitePublicController($view, $service, $site, new HomeController($view, $appConfig), $media),
        ];
    }
}
