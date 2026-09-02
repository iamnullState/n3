<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use N3\App\Controller\IdentityController;
use N3\App\Identity\IdentityConfig;
use N3\App\Identity\IdentityValidator;
use N3\App\Identity\LocalOutboxNotifier;
use N3\App\Identity\PdoRateLimiter;
use N3\App\Identity\PdoSecurityEventRecorder;
use N3\App\Identity\PdoUserRepository;
use N3\App\Identity\PdoVerificationTokenRepository;
use N3\App\Identity\RegistrationService;
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

final class IdentityRegistrationTest extends TestCase
{
    private PDO $connection;
    private RegistrationService $service;
    private PdoUserRepository $users;
    private string $email;
    private string $outbox;
    private string $ip;
    private IdentityConfig $config;

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
        $migrationConnection = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            $database,
            (string) getenv('N3_TEST_DB_MIGRATION_USER'),
            (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'),
        ));
        (new MigrationRunner($migrationConnection, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $key = str_repeat('integration-key-', 4);
        $this->users = new PdoUserRepository($this->connection);
        $this->outbox = sys_get_temp_dir() . '/n3-outbox-' . bin2hex(random_bytes(8));
        $this->config = new IdentityConfig(true, 'http://n3.test', $key);
        $this->service = new RegistrationService(
            $this->config,
            new IdentityValidator(),
            $this->users,
            new PdoVerificationTokenRepository($this->connection),
            new LocalOutboxNotifier($this->outbox),
            new PdoRateLimiter($this->connection, $key),
            new PdoSecurityEventRecorder($this->connection, $key),
            new TransactionManager($this->connection),
        );
        $this->email = 'identity-' . bin2hex(random_bytes(8)) . '@example.test';
        $this->ip = '10.20.' . random_int(1, 254) . '.' . random_int(1, 254);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection, $this->email)) {
            $this->connection->prepare('DELETE FROM users WHERE email_normalized = :email')->execute(['email' => $this->email]);
        }

        foreach (glob(($this->outbox ?? '') . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        if (isset($this->outbox) && is_dir($this->outbox)) {
            rmdir($this->outbox);
        }
    }

    public function testRegistrationStoresOnlyAHashedTokenAndVerificationIsSingleUse(): void
    {
        $outcome = $this->service->register(
            '<script>Ada</script>',
            $this->email,
            'correct horse battery staple',
            'correct horse battery staple',
            $this->ip,
            '1234567890abcdef',
        );

        self::assertTrue($outcome->accepted());
        $user = $this->users->findByNormalizedEmail($this->email);
        self::assertNotNull($user);
        self::assertSame('member', $user->role);
        self::assertSame('pending_verification', $user->status);
        self::assertSame('<script>Ada</script>', $user->displayName);

        $files = glob($this->outbox . '/*.json') ?: [];
        self::assertCount(1, $files);
        $message = json_decode((string) file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
        parse_str((string) parse_url($message['verification_url'], PHP_URL_QUERY), $query);
        $rawToken = (string) ($query['token'] ?? '');
        self::assertNotSame('', $rawToken);

        $storedHash = (string) $this->connection->query('SELECT token_hash FROM email_verification_tokens ORDER BY id DESC LIMIT 1')->fetchColumn();
        self::assertSame(hash('sha256', $rawToken), $storedHash);
        self::assertStringNotContainsString($rawToken, $storedHash);
        self::assertTrue($this->service->verify($storedHash, $this->ip, '1234567890abcdef'));
        self::assertFalse($this->service->verify($storedHash, $this->ip, '1234567890abcdef'));

        $verified = $this->users->findByNormalizedEmail($this->email);
        self::assertTrue($verified?->emailVerified ?? false);
        self::assertSame('active', $verified?->status);
    }

    public function testDebugRegistrationActivatesWithoutTokenOrNotification(): void
    {
        $config = new IdentityConfig(
            true,
            'http://n3.test',
            $this->config->securityHashKey,
            mailDriver: 'local_outbox',
            emailVerificationRequired: false,
        );
        $service = new RegistrationService(
            $config,
            new IdentityValidator(),
            $this->users,
            new PdoVerificationTokenRepository($this->connection),
            new LocalOutboxNotifier($this->outbox),
            new PdoRateLimiter($this->connection, $config->securityHashKey),
            new PdoSecurityEventRecorder($this->connection, $config->securityHashKey),
            new TransactionManager($this->connection),
        );

        self::assertTrue($service->register(
            'Debug Member',
            $this->email,
            'correct horse battery staple',
            'correct horse battery staple',
            $this->ip,
            '1234567890abcdef',
        )->accepted());
        $user = $this->users->findByNormalizedEmail($this->email);
        self::assertSame('active', $user?->status);
        self::assertTrue($user?->emailVerified ?? false);
        self::assertSame([], glob($this->outbox . '/*.json') ?: []);
        $tokenCount = $this->connection->prepare('SELECT COUNT(*) FROM email_verification_tokens WHERE user_id = :id');
        $tokenCount->execute(['id' => $user?->id]);
        self::assertSame(0, (int) $tokenCount->fetchColumn());
        $outcome = $this->connection->prepare(
            "SELECT outcome FROM security_events WHERE user_id = :id AND event_type = 'registration' ORDER BY id DESC LIMIT 1",
        );
        $outcome->execute(['id' => $user?->id]);
        self::assertSame('created_debug_verified', $outcome->fetchColumn());
    }

    public function testDuplicateRegistrationReturnsTheSameAcceptedOutcome(): void
    {
        $arguments = ['Member', $this->email, 'correct horse battery staple', 'correct horse battery staple', $this->ip, '1234567890abcdef'];
        self::assertTrue($this->service->register(...$arguments)->accepted());
        self::assertTrue($this->service->register(...$arguments)->accepted());
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM users WHERE email_normalized = :email');
        $statement->execute(['email' => $this->email]);
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    public function testControllerUsesCsrfAndPostRedirectGetForTheVerificationFlow(): void
    {
        $session = new ArraySessionStore();
        $csrf = new CsrfTokenManager($session);
        $controller = new IdentityController(
            new View(dirname(__DIR__, 2) . '/resources/views'),
            $this->config,
            $this->service,
            $csrf,
            new FlashBag($session),
            $session,
        );
        $form = $controller->showRegister(Request::create('GET', '/register'));
        self::assertSame(200, $form->status());
        self::assertStringContainsString('Create an account', $form->body());

        $rejected = $controller->register(Request::create('POST', '/register', [
            '_csrf' => 'invalid',
        ]));
        self::assertSame(419, $rejected->status());

        $created = $controller->register(Request::create('POST', '/register', [
            '_csrf' => $csrf->token('register'),
            'display_name' => '<script>Feature Member</script>',
            'email' => $this->email,
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ], ['REMOTE_ADDR' => $this->ip])->withAttribute('request_id', '1234567890abcdef'));
        self::assertSame(303, $created->status());
        self::assertSame('/register', $created->headers()['Location']);

        $messageFile = (glob($this->outbox . '/*.json') ?: [])[0] ?? '';
        $message = json_decode((string) file_get_contents($messageFile), true, flags: JSON_THROW_ON_ERROR);
        $captured = $controller->showVerify(Request::create('GET', (string) $message['verification_url']));
        self::assertSame(303, $captured->status());
        self::assertSame('/verify-email', $captured->headers()['Location']);

        $confirmation = $controller->showVerify(Request::create('GET', '/verify-email'));
        self::assertStringContainsString('Confirm that you want to verify', $confirmation->body());
        self::assertStringNotContainsString((string) $message['verification_url'], $confirmation->body());

        $verified = $controller->verify(Request::create('POST', '/verify-email', [
            '_csrf' => $csrf->token('verify_email'),
        ], ['REMOTE_ADDR' => $this->ip])->withAttribute('request_id', '1234567890abcdef'));
        self::assertSame(303, $verified->status());
        self::assertTrue($this->users->findByNormalizedEmail($this->email)?->emailVerified ?? false);
    }
}
