<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\App\Controller\InstallerController;
use N3\App\Install\InstallerAttemptLimiter;
use N3\App\Install\InstallerConfig;
use N3\App\Install\InstallerOperations;
use N3\App\Install\InstallerPreflight;
use N3\Core\Http\Request;
use N3\Core\Logging\FileLogger;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\ArraySessionStore;
use N3\Core\Session\FlashBag;
use N3\Core\View\View;
use PHPUnit\Framework\TestCase;

final class InstallerControllerTest extends TestCase
{
    private ArraySessionStore $session;
    private CsrfTokenManager $csrf;
    private FakeInstallerOperations $operations;
    private InstallerController $controller;

    protected function setUp(): void
    {
        $this->session = new ArraySessionStore();
        $this->csrf = new CsrfTokenManager($this->session);
        $this->operations = new FakeInstallerOperations();
        $this->controller = new InstallerController(
            new View(dirname(__DIR__, 2) . '/resources/views'),
            new InstallerConfig('test', 'http://example.test', str_repeat('s', 32), str_repeat('t', 32)),
            $this->operations,
            $this->csrf,
            new FlashBag($this->session),
            $this->session,
            new InstallerAttemptLimiter($this->session),
            new FileLogger(sys_get_temp_dir() . '/n3-installer-test-does-not-exist/log'),
        );
    }

    public function testSetupRequiresTheIndependentTokenWithoutExposingItOrBranding(): void
    {
        $response = $this->controller->show(Request::create('GET', '/install'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Installation access', $response->body());
        self::assertStringNotContainsString(str_repeat('t', 32), $response->body());
        self::assertStringNotContainsString('N3', $response->body());
        self::assertSame('no-store, private', $response->headers()['Cache-Control']);
    }

    public function testAuthorizationIsGenericAndSuccessfulTokenIsScrubbedByRedirect(): void
    {
        $rejected = $this->controller->authorize(Request::create('POST', '/install/authorize', ['install_token' => 'wrong']));
        self::assertSame(403, $rejected->status());
        self::assertStringNotContainsString('wrong', $rejected->body());

        $accepted = $this->controller->authorize(Request::create('POST', '/install/authorize', [
            'install_token' => str_repeat('t', 32),
        ]));
        self::assertSame(303, $accepted->status());
        self::assertSame('/install', $accepted->headers()['Location']);
        self::assertStringNotContainsString(str_repeat('t', 32), $accepted->body());
    }

    public function testMigrationRequiresCsrfAndEscapesReadOnlyDetails(): void
    {
        $this->authorize();
        $expired = $this->controller->migrate(Request::create('POST', '/install/migrate'));
        self::assertSame(419, $expired->status());

        $this->operations->details['database_name'] = '<script>alert(1)</script>';
        $page = $this->controller->show(Request::create('GET', '/install'));
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page->body());
        self::assertStringNotContainsString('<script>alert(1)</script>', $page->body());

        $response = $this->controller->migrate(Request::create('POST', '/install/migrate', [
            '_csrf' => $this->csrf->token('install_migrate'),
        ]));
        self::assertSame(303, $response->status());
        self::assertSame(1, $this->operations->migrationRuns);
    }

    public function testAdministratorValidationDoesNotEchoPasswordsAndCompletionRedirectsToLogin(): void
    {
        $this->authorize();
        $this->operations->state = 'pending_admin';
        $invalid = $this->controller->createAdmin(Request::create('POST', '/install/admin', [
            '_csrf' => $this->csrf->token('install_admin'),
            'display_name' => '<Admin>', 'email' => 'bad', 'password' => 'private-secret',
            'password_confirmation' => 'different-secret',
        ]));
        self::assertSame(422, $invalid->status());
        self::assertStringNotContainsString('private-secret', $invalid->body());
        self::assertStringContainsString('&lt;Admin&gt;', $invalid->body());

        $response = $this->controller->createAdmin(Request::create('POST', '/install/admin', [
            '_csrf' => $this->csrf->token('install_admin'),
            'display_name' => 'Site Admin', 'email' => 'admin@example.test',
            'password' => 'a secure passphrase', 'password_confirmation' => 'a secure passphrase',
        ]));
        self::assertSame(303, $response->status());
        self::assertSame('/login', $response->headers()['Location']);
        self::assertTrue($this->operations->completed);
        self::assertTrue($this->operations->admin);
    }

    public function testInterruptedAdminCreationCanFinalizeWithoutASecondAccount(): void
    {
        $this->authorize();
        $this->operations->state = 'pending_admin';
        $this->operations->admin = true;
        $page = $this->controller->show(Request::create('GET', '/install'));
        self::assertStringContainsString('Finish interrupted setup', $page->body());
        self::assertStringNotContainsString('name="password"', $page->body());

        $response = $this->controller->complete(Request::create('POST', '/install/complete', [
            '_csrf' => $this->csrf->token('install_complete'),
        ]));
        self::assertSame('/login', $response->headers()['Location']);
        self::assertSame(0, $this->operations->adminCreations);
    }

    private function authorize(): void
    {
        $this->controller->authorize(Request::create('POST', '/install/authorize', [
            'install_token' => str_repeat('t', 32),
        ]));
    }
}

final class FakeInstallerOperations implements InstallerOperations
{
    public string $state = 'migrations_pending';
    public bool $admin = false;
    public bool $completed = false;
    public int $migrationRuns = 0;
    public int $adminCreations = 0;
    /** @var array<string, string> */
    public array $details = ['database_name' => 'cms'];

    public function status(): string { return $this->state; }
    public function preflight(Request $request): InstallerPreflight
    {
        return new InstallerPreflight([
            'php' => true, 'pdo_mysql' => true, 'mbstring' => true, 'installer_secrets' => true, 'https' => true,
            'private_storage' => true, 'separate_database_accounts' => true, 'database' => true, 'module_extensions' => true,
        ], $this->details);
    }
    public function applyMigrations(): void { $this->migrationRuns++; $this->state = 'pending_admin'; }
    public function validateAdmin(string $name, string $email, string $password, string $confirmation): array
    {
        $errors = [];
        if (strlen(trim($name)) < 2) { $errors['display_name'] = 'Display name is invalid.'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Email is invalid.'; }
        if (strlen($password) < 12) { $errors['password'] = 'Password is invalid.'; }
        if (!hash_equals($password, $confirmation)) { $errors['password_confirmation'] = 'Passwords do not match.'; }
        return $errors;
    }
    public function createAdmin(string $name, string $email, string $password): void
    {
        $this->adminCreations++;
        $this->admin = true;
    }
    public function adminExists(): bool { return $this->admin; }
    public function complete(): void { $this->completed = true; $this->state = 'complete'; }
}
