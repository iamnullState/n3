<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use N3\App\Content\PageService;
use N3\App\Content\PageMediaAttachment;
use N3\App\Content\PageMediaMutationOutcome;
use N3\App\Content\PageMediaOption;
use N3\App\Content\PageMediaProvider;
use N3\App\Content\PageValidator;
use N3\App\Content\PdoContentEventRecorder;
use N3\App\Content\PdoPageRepository;
use N3\App\Controller\AdminPageController;
use N3\App\Controller\PublicPageController;
use N3\App\Identity\AuthSessionManager;
use N3\App\Identity\PdoUserRepository;
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

final class PageContentTest extends TestCase
{
    private PDO $connection;
    private PdoUserRepository $users;
    private PdoPageRepository $repository;
    private PageService $service;
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
        $this->connection = $factory->create(new DatabaseConfig((string) getenv('N3_TEST_DB_HOST'), (int) getenv('N3_TEST_DB_PORT'), $database, (string) getenv('N3_TEST_DB_USER'), (string) getenv('N3_TEST_DB_PASSWORD')));
        $migration = $factory->create(new DatabaseConfig((string) getenv('N3_TEST_DB_HOST'), (int) getenv('N3_TEST_DB_PORT'), $database, (string) getenv('N3_TEST_DB_MIGRATION_USER'), (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD')));
        (new MigrationRunner($migration, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->email = 'page-admin-' . bin2hex(random_bytes(8)) . '@example.test';
        $this->requestId = bin2hex(random_bytes(8));
        $this->users = new PdoUserRepository($this->connection);
        $this->adminId = $this->users->createAdmin('Page Admin', $this->email, $this->email, password_hash('test administrator passphrase', PASSWORD_DEFAULT));
        $this->repository = new PdoPageRepository($this->connection);
        $this->service = new PageService(new PageValidator(), $this->repository, new PdoContentEventRecorder($this->connection), new TransactionManager($this->connection));
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection, $this->adminId)) { return; }
        $this->connection->prepare('DELETE FROM content_events WHERE actor_user_id = :id')->execute(['id' => $this->adminId]);
        $this->connection->prepare('DELETE FROM pages WHERE author_id = :id')->execute(['id' => $this->adminId]);
        $this->connection->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->adminId]);
    }

    public function testDraftLifecycleIsAuditedOptimisticAndPublicOnlyWhenPublished(): void
    {
        $created = $this->service->createDraft('Hello <script>', 'hello-page', 'A summary', "First line\nSecond <b>line</b>", $this->adminId, $this->requestId);
        self::assertTrue($created->succeeded());
        self::assertNull($this->service->findPublished('hello-page'));
        $page = $this->service->find($created->pageId);
        self::assertSame('draft', $page?->status);

        $updated = $this->service->updateDraft($page->id, 'Updated title', 'hello-page', 'A summary', $page->body, $this->adminId, $page->lockVersion, $this->requestId);
        self::assertTrue($updated->succeeded());
        $stale = $this->service->updateDraft($page->id, 'Stale title', 'hello-page', '', '', $this->adminId, $page->lockVersion, $this->requestId);
        self::assertTrue($stale->conflict);

        $current = $this->service->find($page->id);
        self::assertTrue($this->service->publish($page->id, $this->adminId, $current->lockVersion, $this->requestId)->succeeded());
        self::assertSame('published', $this->service->findPublished('hello-page')?->status);
        $published = $this->service->find($page->id);
        self::assertTrue($this->service->updateDraft($page->id, 'Unsafe live edit', 'hello-page', '', 'changed', $this->adminId, $published->lockVersion, $this->requestId)->conflict);
        self::assertTrue($this->service->unpublish($page->id, $this->adminId, $published->lockVersion, $this->requestId)->succeeded());
        self::assertNull($this->service->findPublished('hello-page'));
        $eventStatement = $this->connection->prepare('SELECT event_type FROM content_events WHERE actor_user_id = :id ORDER BY id');
        $eventStatement->execute(['id' => $this->adminId]);
        $events = $eventStatement->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['created', 'updated', 'published', 'unpublished'], $events);
    }

    public function testDuplicateSlugAndEmptyBodyPublishingFailSafely(): void
    {
        self::assertTrue($this->service->createDraft('First', 'same-slug', '', 'body', $this->adminId, $this->requestId)->succeeded());
        self::assertArrayHasKey('slug', $this->service->createDraft('Second', 'same-slug', '', 'body', $this->adminId, $this->requestId)->errors);
        $blank = $this->service->createDraft('Blank', 'blank-page', '', '', $this->adminId, $this->requestId);
        $page = $this->service->find($blank->pageId);
        self::assertArrayHasKey('body', $this->service->publish($page->id, $this->adminId, $page->lockVersion, $this->requestId)->errors);
    }

    public function testControllersEnforceAdminCsrfAndEscapePublicContent(): void
    {
        $session = new ArraySessionStore();
        $csrf = new CsrfTokenManager($session);
        $auth = new AuthSessionManager($session, $csrf, $this->users, 1800, 43200);
        $controller = new AdminPageController(new View(dirname(__DIR__, 2) . '/resources/views'), $this->service, $auth, $csrf, new FlashBag($session));
        self::assertSame('/login', $controller->index(Request::create('GET', '/admin/pages'))->headers()['Location']);
        $auth->login($this->users->findById($this->adminId));
        self::assertStringContainsString('No pages yet', $controller->index(Request::create('GET', '/admin/pages'))->body());
        self::assertSame(419, $controller->store(Request::create('POST', '/admin/pages', ['_csrf' => 'invalid']))->status());
        $created = $controller->store(Request::create('POST', '/admin/pages', [
            '_csrf' => $csrf->token('page_create'), 'title' => '<script>Safe title</script>', 'slug' => 'safe-page',
            'excerpt' => '<img src=x>', 'body' => '<script>alert(1)</script>',
        ])->withAttribute('request_id', $this->requestId));
        self::assertSame(303, $created->status());
        self::assertMatchesRegularExpression('#^/admin/pages/[0-9]+/edit$#', $created->headers()['Location']);
        preg_match('#/admin/pages/([0-9]+)/edit#', $created->headers()['Location'], $matches);
        $page = $this->service->find((int) $matches[1]);
        self::assertSame(404, $controller->updateMedia(
            Request::create('POST', '/admin/pages/' . $page->id . '/media')->withAttribute('route_parameters', ['id' => (string) $page->id]),
        )->status());
        $pageRequest = Request::create('POST', '/admin/pages/' . $page->id . '/publish', ['_csrf' => 'invalid', 'lock_version' => (string) $page->lockVersion])
            ->withAttribute('route_parameters', ['id' => (string) $page->id]);
        self::assertSame(419, $controller->publish($pageRequest)->status());
        $mediaProvider = new FeaturePageMediaProvider();
        $mediaController = new AdminPageController(new View(dirname(__DIR__, 2) . '/resources/views'), $this->service, $auth, $csrf, new FlashBag($session), $mediaProvider);
        $mediaRoute = ['route_parameters' => ['id' => (string) $page->id]];
        self::assertSame(419, $mediaController->updateMedia(Request::create('POST', '/admin/pages/' . $page->id . '/media', [
            '_csrf' => 'invalid', 'lock_version' => (string) $page->lockVersion,
        ])->withAttribute('route_parameters', $mediaRoute['route_parameters']))->status());
        self::assertSame(422, $mediaController->updateMedia(Request::create('POST', '/admin/pages/' . $page->id . '/media', [
            '_csrf' => $csrf->token('page_media_' . $page->id), 'lock_version' => (string) $page->lockVersion,
            'media_id' => ['nested'], 'alt_text' => 'Description',
        ])->withAttribute('route_parameters', $mediaRoute['route_parameters'])->withAttribute('request_id', $this->requestId))->status());
        $attached = $mediaController->updateMedia(Request::create('POST', '/admin/pages/' . $page->id . '/media', [
            '_csrf' => $csrf->token('page_media_' . $page->id), 'lock_version' => (string) $page->lockVersion,
            'media_id' => str_repeat('a', 32), 'alt_text' => 'A <bright> accessible image',
        ])->withAttribute('route_parameters', $mediaRoute['route_parameters'])->withAttribute('request_id', $this->requestId));
        self::assertSame(303, $attached->status());
        self::assertTrue($this->service->publish($page->id, $this->adminId, $page->lockVersion, $this->requestId)->succeeded());
        $public = (new PublicPageController(new View(dirname(__DIR__, 2) . '/resources/views'), $this->service, $mediaProvider))->show(
            Request::create('GET', '/pages/safe-page')->withAttribute('route_parameters', ['slug' => 'safe-page']),
        );
        self::assertSame(200, $public->status());
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $public->body());
        self::assertStringNotContainsString('<script>alert(1)</script>', $public->body());
        self::assertStringContainsString('/media/' . str_repeat('a', 32) . '.webp', $public->body());
        self::assertStringContainsString('alt="A &lt;bright&gt; accessible image"', $public->body());
        self::assertStringNotContainsString('Private & internal label', $public->body());
        self::assertSame(404, (new PublicPageController(new View(dirname(__DIR__, 2) . '/resources/views'), $this->service))->show(
            Request::create('GET', '/pages/missing')->withAttribute('route_parameters', ['slug' => 'missing']),
        )->status());
        self::assertSame(404, (new PublicPageController(new View(dirname(__DIR__, 2) . '/resources/views'), $this->service))->show(
            Request::create('GET', '/pages/SAFE-PAGE')->withAttribute('route_parameters', ['slug' => 'SAFE-PAGE']),
        )->status());

        $memberEmail = 'page-member-' . bin2hex(random_bytes(8)) . '@example.test';
        $memberId = $this->users->createPending('Member', $memberEmail, $memberEmail, password_hash('test member passphrase', PASSWORD_DEFAULT));
        $this->users->markEmailVerified($memberId);
        $memberSession = new ArraySessionStore();
        $memberCsrf = new CsrfTokenManager($memberSession);
        $memberAuth = new AuthSessionManager($memberSession, $memberCsrf, $this->users, 1800, 43200);
        $memberAuth->login($this->users->findById($memberId));
        $memberController = new AdminPageController(new View(dirname(__DIR__, 2) . '/resources/views'), $this->service, $memberAuth, $memberCsrf, new FlashBag($memberSession));
        try {
            self::assertSame(403, $memberController->index(Request::create('GET', '/admin/pages'))->status());
        } finally {
            $this->connection->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $memberId]);
        }
    }
}

final class FeaturePageMediaProvider implements PageMediaProvider
{
    private ?PageMediaAttachment $attachment = null;

    public function options(int $pageId): array
    {
        return [new PageMediaOption(str_repeat('a', 32), 'Private & internal label', 1200, 800)];
    }

    public function attachment(int $pageId): ?PageMediaAttachment
    {
        return $this->attachment;
    }

    public function updateDraft(int $pageId, ?string $publicId, string $altText, int $actorId, int $expectedVersion, string $requestId): PageMediaMutationOutcome
    {
        $this->attachment = $publicId === null ? null : new PageMediaAttachment($publicId, $altText, 1200, 800);
        return new PageMediaMutationOutcome();
    }
}
