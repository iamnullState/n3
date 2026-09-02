<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use N3\App\Content\PageService;
use N3\App\Content\PageValidator;
use N3\App\Content\PdoContentEventRecorder;
use N3\App\Content\PdoPageRepository;
use N3\App\Identity\PdoUserRepository;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\MigrationRunner;
use N3\Core\Database\TransactionManager;
use N3\Core\Module\ModuleMigrationRunner;
use N3\Module\Media\MediaAsset;
use N3\Module\Media\MediaModule;
use N3\Module\Media\MediaSchema;
use N3\Module\Media\PageMediaService;
use N3\Module\Media\PdoMediaRepository;
use N3\Module\Media\PdoPageMediaRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PageMediaIntegrationTest extends TestCase
{
    private PDO $migration;
    private PDO $connection;
    private int $adminId;
    private int $pageId;

    protected function setUp(): void
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) { $this->markTestSkipped('pdo_mysql is not installed.'); }
        foreach (['N3_TEST_DB_HOST', 'N3_TEST_DB_PORT', 'N3_TEST_DB_NAME', 'N3_TEST_DB_USER', 'N3_TEST_DB_PASSWORD', 'N3_TEST_DB_MIGRATION_USER', 'N3_TEST_DB_MIGRATION_PASSWORD'] as $key) {
            if (!getenv($key)) { $this->markTestSkipped(sprintf('%s is not configured.', $key)); }
        }
        $database = (string) getenv('N3_TEST_DB_NAME');
        if (!str_ends_with($database, '_test')) { throw new RuntimeException('Integration database names must end in _test.'); }
        $factory = new ConnectionFactory();
        $this->connection = $factory->create(new DatabaseConfig((string) getenv('N3_TEST_DB_HOST'), (int) getenv('N3_TEST_DB_PORT'), $database, (string) getenv('N3_TEST_DB_USER'), (string) getenv('N3_TEST_DB_PASSWORD')));
        $this->migration = $factory->create(new DatabaseConfig((string) getenv('N3_TEST_DB_HOST'), (int) getenv('N3_TEST_DB_PORT'), $database, (string) getenv('N3_TEST_DB_MIGRATION_USER'), (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD')));
        (new MigrationRunner($this->migration, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->removeMediaSchema();
        (new ModuleMigrationRunner($this->migration, [new MediaModule()]))->migrate();

        $email = 'page-media-' . bin2hex(random_bytes(8)) . '@example.test';
        $users = new PdoUserRepository($this->connection);
        $this->adminId = $users->createAdmin('Media Page Admin', $email, $email, password_hash('test administrator passphrase', PASSWORD_DEFAULT));
        $pages = new PageService(new PageValidator(), new PdoPageRepository($this->connection), new PdoContentEventRecorder($this->connection), new TransactionManager($this->connection));
        $created = $pages->createDraft('Media page', 'media-page-' . bin2hex(random_bytes(4)), '', 'Page body', $this->adminId, str_repeat('a', 16));
        $this->pageId = $created->pageId ?? 0;

        (new PdoMediaRepository($this->connection, new TransactionManager($this->connection), str_repeat('s', 32)))->create(new MediaAsset(
            str_repeat('b', 32), 'Private library label', 1200, 800, 42_000, str_repeat('c', 64),
            new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
        ));
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection, $this->migration)) { return; }
        $this->removeMediaSchema();
        $this->connection->prepare('DELETE FROM content_events WHERE actor_user_id = :id')->execute(['id' => $this->adminId]);
        $this->connection->prepare('DELETE FROM pages WHERE author_id = :id')->execute(['id' => $this->adminId]);
        $this->connection->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->adminId]);
    }

    public function testAttachmentLifecycleIsDraftOnlyOptimisticAuditedAndPubliclyAuthorized(): void
    {
        $repository = new PdoPageMediaRepository($this->connection, new TransactionManager($this->connection));
        $service = new PageMediaService($repository);
        self::assertSame(['Private library label'], array_column($service->options($this->pageId), 'label'));
        $pageRepository = new PdoPageRepository($this->connection);
        $page = $pageRepository->findById($this->pageId);
        self::assertNotNull($page);

        $attached = $service->updateDraft($page->id, str_repeat('b', 32), 'A mountain above a quiet lake', $this->adminId, $page->lockVersion, str_repeat('d', 16));
        self::assertTrue($attached->succeeded());
        self::assertSame('A mountain above a quiet lake', $service->attachment($page->id)?->altText);
        self::assertFalse($repository->isPubliclyAttached(str_repeat('b', 32)));
        self::assertTrue($service->updateDraft($page->id, str_repeat('b', 32), 'Stale description', $this->adminId, $page->lockVersion, str_repeat('e', 16))->conflict);

        $pages = new PageService(new PageValidator(), $pageRepository, new PdoContentEventRecorder($this->connection), new TransactionManager($this->connection));
        $current = $pageRepository->findById($page->id);
        self::assertTrue($pages->publish($page->id, $this->adminId, $current->lockVersion, str_repeat('f', 16))->succeeded());
        self::assertTrue($repository->isPubliclyAttached(str_repeat('b', 32)));
        $published = $pageRepository->findById($page->id);
        self::assertTrue($service->updateDraft($page->id, null, '', $this->adminId, $published->lockVersion, str_repeat('1', 16))->conflict);

        self::assertTrue($pages->unpublish($page->id, $this->adminId, $published->lockVersion, str_repeat('2', 16))->succeeded());
        $draft = $pageRepository->findById($page->id);
        self::assertTrue($service->updateDraft($page->id, null, '', $this->adminId, $draft->lockVersion, str_repeat('3', 16))->succeeded());
        self::assertNull($service->attachment($page->id));
        self::assertFalse($repository->isPubliclyAttached(str_repeat('b', 32)));

        $events = $this->connection->prepare('SELECT event_type FROM content_events WHERE page_id = :page_id ORDER BY id');
        $events->execute(['page_id' => $page->id]);
        self::assertSame(['created', 'media_attached', 'published', 'unpublished', 'media_detached'], $events->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testAttachmentSchemaContainsNoPublicUrlsOrInternalLabels(): void
    {
        $columns = array_column($this->migration->query(sprintf('SHOW COLUMNS FROM `%s`', MediaSchema::attachmentsTable()))->fetchAll(), 'Field');
        self::assertSame(['page_id', 'asset_public_id', 'alt_text', 'created_at', 'updated_at'], $columns);
        foreach (['url', 'path', 'label', 'filename', 'ip', 'payload'] as $forbidden) { self::assertNotContains($forbidden, $columns); }
        $statement = $this->connection->prepare(sprintf(
            'INSERT INTO `%s` (page_id, asset_public_id, alt_text, created_at, updated_at) '
            . 'VALUES (:page_id, :asset_public_id, :alt_text, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
            MediaSchema::attachmentsTable(),
        ));
        try {
            $statement->execute(['page_id' => $this->pageId, 'asset_public_id' => str_repeat('b', 32), 'alt_text' => '']);
            self::fail('The database accepted blank attachment alternative text.');
        } catch (\PDOException $exception) {
            self::assertSame('23000', (string) $exception->getCode());
        }
    }

    private function removeMediaSchema(): void
    {
        foreach ([MediaSchema::attachmentsTable(), MediaSchema::limitsTable(), MediaSchema::eventsTable(), MediaSchema::assetsTable()] as $table) {
            $this->migration->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
        foreach (['module_migrations', 'module_events', 'modules'] as $table) {
            $this->migration->prepare(sprintf('DELETE FROM %s WHERE module_id = :module_id', $table))->execute(['module_id' => MediaSchema::MODULE_ID]);
        }
    }
}
