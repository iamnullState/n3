<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use N3\App\Content\PageService;
use N3\App\Content\PageValidator;
use N3\App\Content\PdoContentEventRecorder;
use N3\App\Content\PdoPageRepository;
use N3\App\Controller\HomeController;
use N3\App\Controller\SiteAdminController;
use N3\App\Controller\SitePublicController;
use N3\App\Identity\AuthSessionManager;
use N3\App\Identity\PdoUserRepository;
use N3\App\Site\PdoSiteRepository;
use N3\App\Site\SiteService;
use N3\App\Site\SiteValidator;
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

final class SiteScaffoldTest extends TestCase
{
    private PDO $connection;
    private PdoUserRepository $users;
    private PageService $pages;
    private SiteService $site;
    private int $adminId;
    private string $email;
    private string $requestId;

    protected function setUp(): void
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql is not installed.');
        }
        foreach (['N3_TEST_DB_HOST', 'N3_TEST_DB_PORT', 'N3_TEST_DB_NAME', 'N3_TEST_DB_USER', 'N3_TEST_DB_PASSWORD', 'N3_TEST_DB_MIGRATION_USER', 'N3_TEST_DB_MIGRATION_PASSWORD'] as $key) {
            if (!getenv($key)) { $this->markTestSkipped(sprintf('%s is not configured.', $key)); }
        }
        $database = (string) getenv('N3_TEST_DB_NAME');
        if (!str_ends_with($database, '_test')) { throw new RuntimeException('Integration database names must end in _test.'); }
        $factory = new ConnectionFactory();
        $this->connection = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'), (int) getenv('N3_TEST_DB_PORT'), $database,
            (string) getenv('N3_TEST_DB_USER'), (string) getenv('N3_TEST_DB_PASSWORD'),
        ));
        $migration = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'), (int) getenv('N3_TEST_DB_PORT'), $database,
            (string) getenv('N3_TEST_DB_MIGRATION_USER'), (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'),
        ));
        (new MigrationRunner($migration, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->clearSiteFixture();

        $this->email = 'site-admin-' . bin2hex(random_bytes(8)) . '@example.test';
        $this->requestId = bin2hex(random_bytes(8));
        $this->users = new PdoUserRepository($this->connection);
        $this->adminId = $this->users->createAdmin(
            'Site Admin', $this->email, $this->email, password_hash('test administrator passphrase', PASSWORD_DEFAULT),
        );
        $transactions = new TransactionManager($this->connection);
        $this->pages = new PageService(
            new PageValidator(), new PdoPageRepository($this->connection),
            new PdoContentEventRecorder($this->connection), $transactions,
        );
        $this->site = new SiteService(new PdoSiteRepository($this->connection, $transactions), new SiteValidator());
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection, $this->adminId)) { return; }
        $this->clearSiteFixture();
        $this->connection->prepare('DELETE FROM content_events WHERE actor_user_id = :id')->execute(['id' => $this->adminId]);
        $this->connection->prepare('DELETE FROM pages WHERE author_id = :id')->execute(['id' => $this->adminId]);
        $this->connection->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->adminId]);
    }

    public function testFreshScaffoldIsCompleteAuditedAndIdempotent(): void
    {
        $first = $this->site->scaffold(strtoupper($this->email), $this->requestId);
        $second = $this->site->scaffold($this->email, $this->requestId);

        self::assertSame(5, $first->createdPages);
        self::assertSame(0, $first->existingPages);
        self::assertTrue($first->createdSettings);
        self::assertSame(0, $second->createdPages);
        self::assertSame(5, $second->existingPages);
        self::assertFalse($second->createdSettings);
        self::assertSame(['about', 'contact', 'home', 'privacy-policy', 'terms'], $this->defaultSlugs());
        self::assertSame(5, (int) $this->connection->query('SELECT COUNT(*) FROM site_navigation_items')->fetchColumn());
        self::assertCount(5, $this->site->publicNavigation());
        self::assertSame('N3 Site', $this->site->identity()?->name);
        self::assertSame(10, $this->actorCount('content_events'));
        self::assertSame(2, $this->actorCount('site_events'));
        self::assertSame(['scaffold_installed', 'scaffold_installed'], $this->connection->query('SELECT event_key FROM site_events ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testExistingPageIsPreservedAndDraftNavigationStaysPrivate(): void
    {
        $about = $this->pages->createDraft(
            'Existing About', 'about', 'Original excerpt', 'Original private draft', $this->adminId, $this->requestId,
        );
        self::assertTrue($about->succeeded());

        $result = $this->site->scaffold($this->email, $this->requestId);

        self::assertSame(4, $result->createdPages);
        self::assertSame(1, $result->existingPages);
        $preserved = $this->pages->find($about->pageId);
        self::assertSame('Existing About', $preserved?->title);
        self::assertSame('Original private draft', $preserved?->body);
        self::assertSame('draft', $preserved?->status);
        self::assertCount(5, $this->site->administrationNavigation());
        self::assertCount(4, $this->site->publicNavigation());
    }

    public function testScaffoldFailureRollsBackAllOfItsChanges(): void
    {
        foreach ([10, 20, 30, 40, 50, 65530] as $index => $position) {
            $page = $this->pages->createDraft(
                'Obstacle ' . $index, 'obstacle-' . $index, '', 'Fixture', $this->adminId, $this->requestId,
            );
            self::assertTrue($page->succeeded());
            $insert = $this->connection->prepare(
                'INSERT INTO site_navigation_items (page_id, label, position, is_visible) VALUES (:page_id, :label, :position, 1)',
            );
            $insert->execute(['page_id' => $page->pageId, 'label' => 'Obstacle ' . $index, 'position' => $position]);
        }

        try {
            $this->site->scaffold($this->email, $this->requestId);
            self::fail('Expected navigation exhaustion to abort the scaffold.');
        } catch (RuntimeException $exception) {
            self::assertSame('Navigation has no remaining valid position for scaffold items.', $exception->getMessage());
        }

        self::assertSame([], $this->defaultSlugs());
        self::assertSame(0, (int) $this->connection->query('SELECT COUNT(*) FROM site_settings')->fetchColumn());
        self::assertSame(0, $this->actorCount('site_events'));
        self::assertSame(6, (int) $this->connection->query('SELECT COUNT(*) FROM site_navigation_items')->fetchColumn());
    }

    public function testSettingsAreValidatedVersionedEscapedAndReflectedPublicly(): void
    {
        $this->site->scaffold($this->email, $this->requestId);
        $identity = $this->site->identity();
        self::assertNotNull($identity);
        $navigation = [];
        foreach ($this->site->administrationNavigation() as $item) {
            $navigation[(string) $item->pageId] = [
                'label' => $item->slug === 'home' ? 'Home <safe>' : $item->label,
                'position' => (string) $item->position,
                'visible' => $item->slug === 'contact' ? '0' : '1',
            ];
        }
        $updated = $this->site->update(
            'Acme <script>', 'Useful & accessible', 'OWNER@Example.Test', '#173F8F', '/assets/svg/n3.svg',
            $navigation, $this->adminId, $identity->lockVersion, $this->requestId,
        );
        self::assertTrue($updated->succeeded(), json_encode($updated->errors, JSON_THROW_ON_ERROR));
        self::assertSame('owner@example.test', $this->site->identity()?->contactEmail);
        self::assertCount(4, $this->site->publicNavigation());
        self::assertTrue($this->site->update(
            'Stale Site', '', 'owner@example.test', '#173F8F', '', $navigation,
            $this->adminId, $identity->lockVersion, $this->requestId,
        )->conflict);
        self::assertArrayHasKey('navigation', $this->site->update(
            'Safe Site', '', 'owner@example.test', '#173F8F', '',
            ['999999999' => ['label' => 'Missing', 'position' => '10', 'visible' => '1']],
            $this->adminId, 2, $this->requestId,
        )->errors);

        $view = new View(dirname(__DIR__, 2) . '/resources/views');
        $fallback = new HomeController($view, [
            'name' => 'N3', 'version' => '0.2.0', 'environment' => 'testing', 'debug' => false, 'timezone' => 'UTC',
        ]);
        $public = new SitePublicController($view, $this->pages, $this->site, $fallback);
        $home = $public->home(Request::create('GET', '/'));
        self::assertSame(200, $home->status());
        self::assertStringContainsString('Acme &lt;script&gt;', $home->body());
        self::assertStringNotContainsString('Acme <script>', $home->body());
        self::assertStringContainsString('Home &lt;safe&gt;', $home->body());
        self::assertStringNotContainsString('/pages/contact', $home->body());
        self::assertStringContainsString('aria-current="page"', $home->body());
        self::assertLessThan(100 * 1024, strlen($home->body()));

        $css = $public->stylesheet(Request::create('GET', '/site.css'));
        self::assertSame(':root{--brand-primary:#173F8F}', $css->body());
        self::assertSame('nosniff', $css->headers()['X-Content-Type-Options']);
        self::assertSame(304, $public->stylesheet(Request::create('GET', '/site.css', [], [
            'HTTP_IF_NONE_MATCH' => $css->headers()['ETag'],
        ]))->status());

        $homePage = $this->pages->findPublished('home');
        self::assertNotNull($homePage);
        self::assertTrue($this->pages->unpublish(
            $homePage->id, $this->adminId, $homePage->lockVersion, $this->requestId,
        )->succeeded());
        self::assertStringContainsString('Built once. Shaped for every site.', $public->home(Request::create('GET', '/'))->body());
    }

    public function testAdminSettingsRequireAuthenticationAuthorityAndCsrf(): void
    {
        $this->site->scaffold($this->email, $this->requestId);
        $session = new ArraySessionStore();
        $csrf = new CsrfTokenManager($session);
        $auth = new AuthSessionManager($session, $csrf, $this->users, 1800, 43200);
        $controller = new SiteAdminController(
            new View(dirname(__DIR__, 2) . '/resources/views'), $this->site, $auth, $csrf, new FlashBag($session),
        );
        self::assertSame('/login', $controller->edit(Request::create('GET', '/admin/site'))->headers()['Location']);
        $auth->login($this->users->findById($this->adminId));
        $edit = $controller->edit(Request::create('GET', '/admin/site'));
        self::assertSame(200, $edit->status());
        self::assertLessThan(32 * 1024, strlen($edit->body()));
        self::assertSame(419, $controller->update(Request::create('POST', '/admin/site', ['_csrf' => 'invalid']))->status());
    }

    /** @return list<string> */
    private function defaultSlugs(): array
    {
        $rows = $this->connection->query(
            "SELECT slug FROM pages WHERE slug IN ('home','about','contact','privacy-policy','terms') ORDER BY slug",
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $rows);
    }

    private function actorCount(string $table): int
    {
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE actor_user_id = :id');
        $statement->execute(['id' => $this->adminId]);

        return (int) $statement->fetchColumn();
    }

    private function clearSiteFixture(): void
    {
        foreach (['site_events', 'site_navigation_items', 'site_settings'] as $table) {
            $this->connection->exec('DELETE FROM ' . $table);
        }
    }
}
