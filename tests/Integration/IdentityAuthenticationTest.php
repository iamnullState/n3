<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use N3\App\Controller\AccessController;
use N3\App\Identity\AdminBootstrapService;
use N3\App\Identity\AuthenticationService;
use N3\App\Identity\AuthSessionManager;
use N3\App\Identity\IdentityConfig;
use N3\App\Identity\IdentityValidator;
use N3\App\Identity\LocalOutboxNotifier;
use N3\App\Identity\PdoPasswordResetTokenRepository;
use N3\App\Identity\PdoRateLimiter;
use N3\App\Identity\PdoSecurityEventRecorder;
use N3\App\Identity\PdoUserRepository;
use N3\App\Identity\RecoveryService;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\MigrationRunner;
use N3\Core\Database\TransactionManager;
use N3\Core\Http\Request;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\ArraySessionStore;
use N3\Core\Session\FlashBag;
use N3\Core\View\View;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IdentityAuthenticationTest extends TestCase
{
    private PDO $connection;
    private PdoUserRepository $users;
    private PdoPasswordResetTokenRepository $tokens;
    private AuthenticationService $authentication;
    private RecoveryService $recovery;
    private string $email;
    private string $requestId;
    private string $outbox;

    protected function setUp(): void
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql is not installed.');
        }
        foreach (['N3_TEST_DB_HOST', 'N3_TEST_DB_PORT', 'N3_TEST_DB_NAME', 'N3_TEST_DB_USER', 'N3_TEST_DB_PASSWORD', 'N3_TEST_DB_MIGRATION_USER', 'N3_TEST_DB_MIGRATION_PASSWORD'] as $key) {
            if (!getenv($key)) {
                $this->markTestSkipped(sprintf('%s is not configured.', $key));
            }
        }
        $database = (string) getenv('N3_TEST_DB_NAME');
        if (!str_ends_with($database, '_test')) {
            throw new RuntimeException('Integration database names must end in _test.');
        }
        $factory = new ConnectionFactory();
        $this->connection = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            $database,
            (string) getenv('N3_TEST_DB_USER'),
            (string) getenv('N3_TEST_DB_PASSWORD'),
        ));
        $migration = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            $database,
            (string) getenv('N3_TEST_DB_MIGRATION_USER'),
            (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'),
        ));
        (new MigrationRunner($migration, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->email = 'auth-' . bin2hex(random_bytes(8)) . '@example.test';
        $this->requestId = bin2hex(random_bytes(8));
        $this->outbox = sys_get_temp_dir() . '/n3-auth-outbox-' . bin2hex(random_bytes(8));
        $key = str_repeat('authentication-key-', 3);
        $config = new IdentityConfig(true, 'http://n3.test', $key);
        $this->users = new PdoUserRepository($this->connection);
        $this->tokens = new PdoPasswordResetTokenRepository($this->connection);
        $limits = new PdoRateLimiter($this->connection, $key);
        $events = new PdoSecurityEventRecorder($this->connection, $key);
        $notifier = new LocalOutboxNotifier($this->outbox);
        $transactions = new TransactionManager($this->connection);
        $this->authentication = new AuthenticationService(new IdentityValidator(), $this->users, $limits, $events);
        $this->recovery = new RecoveryService($config, new IdentityValidator(), $this->users, $this->tokens, $notifier, $limits, $events, $transactions);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection, $this->requestId)) {
            $this->connection->prepare('DELETE FROM security_events WHERE request_id = :request_id')->execute(['request_id' => $this->requestId]);
            $this->connection->prepare('DELETE FROM users WHERE email_normalized = :email')->execute(['email' => $this->email]);
        }
        foreach (glob(($this->outbox ?? '') . '/*.json') ?: [] as $file) { unlink($file); }
        if (isset($this->outbox) && is_dir($this->outbox)) { rmdir($this->outbox); }
    }

    private function createUser(bool $verified = true): int
    {
        $id = $this->users->createPending('Hostile <script>alert(1)</script>', $this->email, $this->email, password_hash('correct horse battery staple', PASSWORD_DEFAULT));
        if ($verified) { $this->users->markEmailVerified($id); }
        return $id;
    }

    public function testAuthenticationIsGenericAndRequiresAConfirmedActiveAccount(): void
    {
        $this->createUser(false);
        $pending = $this->authentication->authenticate($this->email, 'correct horse battery staple', '127.0.0.41', $this->requestId);
        self::assertFalse($pending->authenticated());
        self::assertTrue($pending->verificationRequired);
        self::assertFalse($this->authentication->authenticate($this->email, 'wrong password value', '127.0.0.42', $this->requestId)->authenticated());
        self::assertFalse($this->authentication->authenticate('missing@example.test', 'wrong password value', '127.0.0.43', $this->requestId)->authenticated());
        $this->users->markEmailVerified($this->users->findByNormalizedEmail($this->email)->id);
        self::assertTrue($this->authentication->authenticate($this->email, 'correct horse battery staple', '127.0.0.44', $this->requestId)->authenticated());
        $this->users->updateStatus($this->users->findByNormalizedEmail($this->email)->id, 'disabled');
        $disabled = $this->authentication->authenticate($this->email, 'correct horse battery staple', '127.0.0.48', $this->requestId);
        self::assertFalse($disabled->authenticated());
        self::assertFalse($disabled->verificationRequired);
    }

    public function testResetTokenIsHashedSingleUseAndInvalidatesExistingSessions(): void
    {
        $id = $this->createUser();
        $user = $this->users->findById($id);
        $session = new ArraySessionStore();
        $csrf = new CsrfTokenManager($session);
        $authSession = new AuthSessionManager($session, $csrf, $this->users, 1800, 43200);
        $authSession->login($user);
        self::assertTrue($this->recovery->request($this->email, '127.0.0.45', $this->requestId));
        $file = (glob($this->outbox . '/*.json') ?: [])[0] ?? '';
        $message = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        parse_str((string) parse_url($message['reset_url'], PHP_URL_QUERY), $query);
        $raw = (string) ($query['token'] ?? '');
        $hash = hash('sha256', $raw);
        self::assertNotSame('', $raw);
        self::assertSame($hash, $this->connection->query('SELECT token_hash FROM password_reset_tokens ORDER BY id DESC LIMIT 1')->fetchColumn());
        self::assertSame([], $this->recovery->reset($hash, 'a fresh password passphrase', 'a fresh password passphrase', '127.0.0.45', $this->requestId));
        self::assertNull($authSession->current());
        self::assertArrayHasKey('form', $this->recovery->reset($hash, 'another fresh passphrase', 'another fresh passphrase', '127.0.0.45', $this->requestId));
        $expiredHash = hash('sha256', 'expired-reset-token');
        $this->tokens->issue($id, $expiredHash, time() - 1);
        self::assertArrayHasKey('form', $this->recovery->reset($expiredHash, 'another fresh passphrase', 'another fresh passphrase', '127.0.0.45', $this->requestId));
        self::assertTrue($this->authentication->authenticate($this->email, 'a fresh password passphrase', '127.0.0.46', $this->requestId)->authenticated());
    }

    public function testControllerEnforcesCsrfEscapesAccountDataAndLogsOut(): void
    {
        $this->createUser();
        $session = new ArraySessionStore();
        $csrf = new CsrfTokenManager($session);
        $authSession = new AuthSessionManager($session, $csrf, $this->users, 1800, 43200);
        $controller = new AccessController(new View(dirname(__DIR__, 2) . '/resources/views'), $this->authentication, $this->recovery, $authSession, $csrf, new FlashBag($session), $session);
        self::assertSame(419, $controller->login(Request::create('POST', '/login', ['_csrf' => 'bad']))->status());
        $login = $controller->login(Request::create('POST', '/login', ['_csrf' => $csrf->token('login'), 'email' => $this->email, 'password' => 'correct horse battery staple'], ['REMOTE_ADDR' => '127.0.0.47'])->withAttribute('request_id', $this->requestId));
        self::assertSame('/account', $login->headers()['Location']);
        $account = $controller->account(Request::create('GET', '/account'));
        self::assertStringContainsString('Hostile &lt;script&gt;alert(1)&lt;/script&gt;', $account->body());
        self::assertStringNotContainsString('Hostile <script>', $account->body());
        self::assertSame(419, $controller->logout(Request::create('POST', '/logout', ['_csrf' => 'bad']))->status());
        self::assertSame('/login', $controller->logout(Request::create('POST', '/logout', ['_csrf' => $csrf->token('logout')]))->headers()['Location']);
        self::assertSame('/login', $controller->account(Request::create('GET', '/account'))->headers()['Location']);
    }

    public function testAdministratorBootstrapAllowsOnlyOneActiveVerifiedAdmin(): void
    {
        $service = new AdminBootstrapService(new IdentityValidator(), $this->users, new TransactionManager($this->connection));
        $id = $service->create('First Admin', $this->email, 'administrator passphrase');
        $admin = $this->users->findById($id);
        self::assertSame('admin', $admin?->role);
        self::assertSame('active', $admin?->status);
        self::assertTrue($admin?->emailVerified ?? false);
        $this->expectException(RuntimeException::class);
        $service->create('Second Admin', 'second-' . $this->email, 'another administrator passphrase');
    }

    public function testRecoveryControllerKeepsBearerTokensOutOfRenderedPages(): void
    {
        $this->createUser();
        $session = new ArraySessionStore();
        $csrf = new CsrfTokenManager($session);
        $authSession = new AuthSessionManager($session, $csrf, $this->users, 1800, 43200);
        $controller = new AccessController(new View(dirname(__DIR__, 2) . '/resources/views'), $this->authentication, $this->recovery, $authSession, $csrf, new FlashBag($session), $session);
        self::assertSame(419, $controller->requestReset(Request::create('POST', '/forgot-password', ['_csrf' => 'bad']))->status());
        $requested = $controller->requestReset(Request::create('POST', '/forgot-password', ['_csrf' => $csrf->token('forgot_password'), 'email' => $this->email], ['REMOTE_ADDR' => '127.0.0.49'])->withAttribute('request_id', $this->requestId));
        self::assertSame('/forgot-password', $requested->headers()['Location']);
        $file = (glob($this->outbox . '/*.json') ?: [])[0] ?? '';
        $message = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        parse_str((string) parse_url($message['reset_url'], PHP_URL_QUERY), $query);
        $raw = (string) ($query['token'] ?? '');
        $captured = $controller->showReset(Request::create('GET', '/reset-password?token=' . rawurlencode($raw)));
        self::assertSame('/reset-password', $captured->headers()['Location']);
        $form = $controller->showReset(Request::create('GET', '/reset-password'));
        self::assertStringContainsString('Choose a new password', $form->body());
        self::assertStringNotContainsString($raw, $form->body());
        $completed = $controller->reset(Request::create('POST', '/reset-password', [
            '_csrf' => $csrf->token('reset_password'),
            'password' => 'controller recovery passphrase',
            'password_confirmation' => 'controller recovery passphrase',
        ], ['REMOTE_ADDR' => '127.0.0.49'])->withAttribute('request_id', $this->requestId));
        self::assertSame('/login', $completed->headers()['Location']);
        self::assertStringContainsString('Your password was changed', $controller->showLogin(Request::create('GET', '/login'))->body());
    }
}
