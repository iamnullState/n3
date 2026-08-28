<?php

declare(strict_types=1);

namespace N3\App\Identity;

use N3\App\Controller\IdentityController;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\TransactionManager;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\FlashBag;
use N3\Core\Session\NativeSessionStore;
use N3\Core\View\View;

final class IdentityKernel
{
    public static function controller(string $root, View $view, string $environment): IdentityController
    {
        $database = require $root . '/config/database.php';
        $connection = (new ConnectionFactory())->create($database);
        $config = IdentityConfig::fromEnvironment($environment);
        $session = new NativeSessionStore($root . '/storage/sessions', $environment === 'production');
        $csrf = new CsrfTokenManager($session);
        $users = new PdoUserRepository($connection);
        $registration = new RegistrationService(
            $config,
            new IdentityValidator(),
            $users,
            new PdoVerificationTokenRepository($connection),
            new LocalOutboxNotifier($root . '/storage/outbox'),
            new PdoRateLimiter($connection, $config->securityHashKey),
            new PdoSecurityEventRecorder($connection, $config->securityHashKey),
            new TransactionManager($connection),
        );

        return new IdentityController($view, $config, $registration, $csrf, new FlashBag($session), $session);
    }
}
