<?php

declare(strict_types=1);

namespace N3\App\Content;

use N3\App\Controller\AdminPageController;
use N3\App\Controller\PublicPageController;
use N3\App\Identity\AuthSessionManager;
use N3\App\Identity\IdentityConfig;
use N3\App\Identity\PdoUserRepository;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\TransactionManager;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\FlashBag;
use N3\Core\Session\NativeSessionStore;
use N3\Core\View\View;

final class ContentKernel
{
    /** @return array{admin: AdminPageController, public: PublicPageController} */
    public static function controllers(string $root, View $view, string $environment): array
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

        return [
            'admin' => new AdminPageController(
                $view,
                $service,
                new AuthSessionManager($session, $csrf, $users, $config->sessionIdleTtl, $config->sessionAbsoluteTtl),
                $csrf,
                new FlashBag($session),
            ),
            'public' => new PublicPageController($view, $service),
        ];
    }
}
