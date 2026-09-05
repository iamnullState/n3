<?php

declare(strict_types=1);

namespace N3\App\Identity;

use N3\App\Controller\IdentityController;
use N3\App\Controller\AccessController;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\TransactionManager;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\FlashBag;
use N3\Core\Session\NativeSessionStore;
use N3\Core\View\View;
use N3\Core\Security\CurrentPrincipalProvider;
use N3\Core\Security\CurrentActorProvider;

final class IdentityKernel
{
    public static function actorProvider(string $root, string $environment): CurrentActorProvider
    {
        $database = require $root . '/config/database.php';
        $connection = (new ConnectionFactory())->create($database);
        $config = IdentityConfig::fromEnvironment($environment);
        $session = new NativeSessionStore($root . '/storage/sessions', $environment === 'production');
        $csrf = new CsrfTokenManager($session);

        return new IdentityActorProvider(new AuthSessionManager(
            $session,
            $csrf,
            new PdoUserRepository($connection),
            $config->sessionIdleTtl,
            $config->sessionAbsoluteTtl,
        ));
    }

    public static function principalProvider(string $root, string $environment): CurrentPrincipalProvider
    {
        $database = require $root . '/config/database.php';
        $connection = (new ConnectionFactory())->create($database);
        $config = IdentityConfig::fromEnvironment($environment);
        $session = new NativeSessionStore($root . '/storage/sessions', $environment === 'production');
        $csrf = new CsrfTokenManager($session);

        return new IdentityPrincipalProvider(new AuthSessionManager(
            $session,
            $csrf,
            new PdoUserRepository($connection),
            $config->sessionIdleTtl,
            $config->sessionAbsoluteTtl,
        ));
    }

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

    public static function accessController(string $root, View $view, string $environment): AccessController
    {
        $database = require $root . '/config/database.php';
        $connection = (new ConnectionFactory())->create($database);
        $config = IdentityConfig::fromEnvironment($environment);
        $session = new NativeSessionStore($root . '/storage/sessions', $environment === 'production');
        $csrf = new CsrfTokenManager($session);
        $users = new PdoUserRepository($connection);
        $rateLimiter = new PdoRateLimiter($connection, $config->securityHashKey);
        $events = new PdoSecurityEventRecorder($connection, $config->securityHashKey);
        $notifier = new LocalOutboxNotifier($root . '/storage/outbox');

        return new AccessController(
            $view,
            new AuthenticationService(new IdentityValidator(), $users, $rateLimiter, $events),
            new RecoveryService(
                $config,
                new IdentityValidator(),
                $users,
                new PdoPasswordResetTokenRepository($connection),
                $notifier,
                $rateLimiter,
                $events,
                new TransactionManager($connection),
            ),
            new AuthSessionManager($session, $csrf, $users, $config->sessionIdleTtl, $config->sessionAbsoluteTtl),
            $csrf,
            new FlashBag($session),
            $session,
        );
    }
}
