<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\MigrationRunner;
use N3\Core\Database\TransactionManager;
use N3\Core\Module\ModuleMigrationRunner;
use N3\Module\Media\MediaAsset;
use N3\Module\Media\MediaModule;
use N3\Module\Media\MediaSchema;
use N3\Module\Media\PdoMediaRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MediaRepositoryTest extends TestCase
{
    private PDO $migrationConnection;
    private PDO $runtimeConnection;

    protected function setUp(): void
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql is not installed.');
        }
        foreach (['N3_TEST_DB_HOST', 'N3_TEST_DB_PORT', 'N3_TEST_DB_NAME', 'N3_TEST_DB_USER', 'N3_TEST_DB_PASSWORD', 'N3_TEST_DB_MIGRATION_USER', 'N3_TEST_DB_MIGRATION_PASSWORD'] as $variable) {
            if (getenv($variable) === false || getenv($variable) === '') {
                $this->markTestSkipped(sprintf('%s is not configured.', $variable));
            }
        }
        $database = (string) getenv('N3_TEST_DB_NAME');
        if (!str_ends_with($database, '_test')) {
            throw new RuntimeException('Integration database names must end in _test.');
        }
        $factory = new ConnectionFactory();
        $this->migrationConnection = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'), (int) getenv('N3_TEST_DB_PORT'), $database,
            (string) getenv('N3_TEST_DB_MIGRATION_USER'), (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'),
        ));
        $this->runtimeConnection = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'), (int) getenv('N3_TEST_DB_PORT'), $database,
            (string) getenv('N3_TEST_DB_USER'), (string) getenv('N3_TEST_DB_PASSWORD'),
        ));
        (new MigrationRunner($this->migrationConnection, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->removeSchema();
        (new ModuleMigrationRunner($this->migrationConnection, [new MediaModule()]))->migrate();
    }

    protected function tearDown(): void
    {
        if (isset($this->migrationConnection)) {
            $this->removeSchema();
        }
    }

    public function testCatalogRateLimitAndAuditDataRemainBoundedAndPrivate(): void
    {
        $repository = new PdoMediaRepository(
            $this->runtimeConnection,
            new TransactionManager($this->runtimeConnection),
            str_repeat('s', 32),
        );
        $asset = new MediaAsset(
            str_repeat('a', 32), 'Safe image', 1200, 800, 42_000, str_repeat('b', 64),
            new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
        );
        $repository->create($asset);

        self::assertSame($asset->publicId, $repository->find($asset->publicId)?->publicId);
        self::assertSame(['Safe image'], array_column($repository->list(100), 'label'));
        self::assertTrue($repository->allowUpload('203.0.113.10', 1_788_278_400, 2));
        self::assertTrue($repository->allowUpload('203.0.113.10', 1_788_278_401, 2));
        self::assertFalse($repository->allowUpload('203.0.113.10', 1_788_278_402, 2));
        $repository->recordEvent('upload_rejected');

        $hash = (string) $this->runtimeConnection->query(sprintf('SELECT subject_hash FROM `%s`', MediaSchema::limitsTable()))->fetchColumn();
        self::assertSame(hash_hmac('sha256', '203.0.113.10', str_repeat('s', 32)), $hash);
        self::assertStringNotContainsString('203.0.113.10', $hash);
        self::assertSame(['upload_succeeded', 'upload_rejected'], array_column($this->runtimeConnection->query(sprintf(
            'SELECT event_key FROM `%s` ORDER BY id', MediaSchema::eventsTable(),
        ))->fetchAll(), 'event_key'));

        $columns = [];
        foreach ([MediaSchema::assetsTable(), MediaSchema::eventsTable(), MediaSchema::limitsTable()] as $table) {
            $columns = [...$columns, ...array_column($this->migrationConnection->query(sprintf('SHOW COLUMNS FROM `%s`', $table))->fetchAll(), 'Field')];
        }
        foreach (['filename', 'original_name', 'mime', 'ip', 'payload', 'metadata', 'source_path'] as $forbidden) {
            self::assertNotContains($forbidden, $columns);
        }
    }

    private function removeSchema(): void
    {
        foreach ([MediaSchema::limitsTable(), MediaSchema::eventsTable(), MediaSchema::assetsTable()] as $table) {
            $this->migrationConnection->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
        $this->migrationConnection->prepare('DELETE FROM module_migrations WHERE module_id = :module_id')->execute(['module_id' => MediaSchema::MODULE_ID]);
        $this->migrationConnection->prepare('DELETE FROM module_events WHERE module_id = :module_id')->execute(['module_id' => MediaSchema::MODULE_ID]);
        $this->migrationConnection->prepare('DELETE FROM modules WHERE module_id = :module_id')->execute(['module_id' => MediaSchema::MODULE_ID]);
    }
}
