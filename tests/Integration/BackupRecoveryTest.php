<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use N3\App\Identity\AdminBootstrapService;
use N3\App\Identity\IdentityValidator;
use N3\App\Identity\PdoUserRepository;
use N3\App\Install\InstallationLock;
use N3\App\Install\PdoInstallationStateRepository;
use N3\Core\Backup\BackupConfig;
use N3\Core\Backup\BackupException;
use N3\Core\Backup\BackupService;
use N3\Core\Backup\MariaDbCliBackupDriver;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\MigrationRunner;
use N3\Core\Database\TablePrefixedPdo;
use N3\Core\Database\TransactionManager;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class BackupRecoveryTest extends TestCase
{
    private TablePrefixedPdo $connection;
    private TablePrefixedPdo $backupConnection;
    private DatabaseConfig $database;
    private DatabaseConfig $backupDatabase;
    private string $prefix = '';
    private string $root = '';

    protected function setUp(): void
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql is not installed.');
        }
        if (!is_executable('/usr/bin/mariadb-dump') || !is_executable('/usr/bin/mariadb')) {
            $this->markTestSkipped('MariaDB backup clients are not installed.');
        }
        foreach (['N3_TEST_DB_HOST', 'N3_TEST_DB_PORT', 'N3_TEST_DB_NAME', 'N3_TEST_DB_MIGRATION_USER', 'N3_TEST_DB_MIGRATION_PASSWORD', 'N3_TEST_DB_BACKUP_USER', 'N3_TEST_DB_BACKUP_PASSWORD'] as $variable) {
            if (getenv($variable) === false || getenv($variable) === '') {
                $this->markTestSkipped(sprintf('%s is not configured.', $variable));
            }
        }
        $name = (string) getenv('N3_TEST_DB_NAME');
        if (!str_ends_with($name, '_test')) {
            throw new RuntimeException('Integration database names must end in _test.');
        }
        $this->prefix = 'b' . bin2hex(random_bytes(5)) . '_';
        $this->database = new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            $name,
            (string) getenv('N3_TEST_DB_MIGRATION_USER'),
            (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'),
            $this->prefix,
        );
        $this->backupDatabase = new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            $name,
            (string) getenv('N3_TEST_DB_BACKUP_USER'),
            (string) getenv('N3_TEST_DB_BACKUP_PASSWORD'),
            $this->prefix,
        );
        $this->connection = new TablePrefixedPdo(
            $this->database->dsn(),
            $this->database->username,
            $this->database->password(),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
            $this->database->tableNames,
        );
        $this->connection->exec("SET time_zone = '+00:00'");
        (new MigrationRunner($this->connection, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        (new AdminBootstrapService(
            new IdentityValidator(),
            new PdoUserRepository($this->connection),
            new TransactionManager($this->connection),
        ))->create('Backup Administrator', 'backup-' . bin2hex(random_bytes(5)) . '@example.test', 'a secure backup test passphrase');
        (new PdoInstallationStateRepository($this->connection))->markComplete();
        $this->backupConnection = new TablePrefixedPdo(
            $this->backupDatabase->dsn(),
            $this->backupDatabase->username,
            $this->backupDatabase->password(),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
            $this->backupDatabase->tableNames,
        );

        $this->root = sys_get_temp_dir() . '/n3-backup-recovery-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root . '/storage/modules/n3/media/data', 0700, true));
        self::assertTrue(mkdir($this->root . '/storage/files', 0700, true));
        self::assertTrue(mkdir($this->root . '/storage/logs', 0700, true));
        self::assertTrue(mkdir($this->root . '/storage/sessions', 0700, true));
        self::assertTrue(mkdir($this->root . '/storage/outbox', 0700, true));
        self::assertTrue(mkdir($this->root . '/public', 0755, true));
        (new InstallationLock($this->root . '/storage/install/installed.lock'))->create();
        file_put_contents($this->root . '/storage/modules/n3/media/data/master.webp', 'private-media-pixels');
        file_put_contents($this->root . '/storage/files/document.txt', 'private-document');
        file_put_contents($this->root . '/storage/logs/app.log', 'excluded-log');
        file_put_contents($this->root . '/storage/sessions/session', 'excluded-session');
        file_put_contents($this->root . '/storage/outbox/message.json', 'excluded-token');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->prefix !== '') {
            $this->dropManagedTables();
        }
        $this->removeTree($this->root);
    }

    public function testEncryptedBundleRestoresDatabaseAndDurableFilesToCleanTarget(): void
    {
        try {
            $this->backupConnection->exec('CREATE TABLE backup_account_must_not_create (id INT PRIMARY KEY)');
            self::fail('The backup account must not have DDL authority.');
        } catch (PDOException $exception) {
            self::assertSame('42000', $exception->getCode());
        }
        $config = new BackupConfig($this->root . '/backups', random_bytes(32), 30);
        $service = new BackupService(
            $this->root,
            '0.2.0',
            $config,
            $this->backupDatabase,
            $this->backupConnection,
            new MariaDbCliBackupDriver('/usr/bin/mariadb-dump', '/usr/bin/mariadb'),
        );

        $created = $service->create();
        $old = (new BackupService(
            $this->root,
            '0.2.0',
            $config,
            $this->backupDatabase,
            $this->backupConnection,
            new MariaDbCliBackupDriver('/usr/bin/mariadb-dump', '/usr/bin/mariadb'),
            clock: (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-40 days'),
        ))->create();
        $manifest = $service->verify($created['id']);
        $paths = array_column($manifest['storage'], 'path');
        self::assertContains('install/installed.lock', $paths);
        self::assertContains('modules/n3/media/data/master.webp', $paths);
        self::assertContains('files/document.txt', $paths);
        self::assertNotContains('logs/app.log', $paths);
        self::assertNotContains('sessions/session', $paths);
        self::assertNotContains('outbox/message.json', $paths);
        self::assertGreaterThanOrEqual(10, $created['database_tables']);
        try {
            (new BackupService(
                $this->root,
                '0.2.0',
                new BackupConfig($this->root . '/backups', random_bytes(32), 30),
            ))->verify($created['id']);
            self::fail('A different backup key must not authenticate the bundle.');
        } catch (BackupException $exception) {
            self::assertStringContainsString('authentication failed', $exception->getMessage());
        }

        $this->dropManagedTables();
        $restoreTarget = $this->root . '/restored-storage';
        $restoreService = new BackupService(
            $this->root,
            '0.2.0',
            $config,
            $this->database,
            $this->connection,
            new MariaDbCliBackupDriver('/usr/bin/mariadb-dump', '/usr/bin/mariadb'),
        );
        $preview = $restoreService->restore($created['id'], $restoreTarget, false);
        self::assertSame($created['database_tables'], $preview['database_tables']);
        self::assertFileDoesNotExist($restoreTarget . '/install/installed.lock');

        $restored = $restoreService->restore($created['id'], $restoreTarget, true);
        self::assertSame($preview, $restored);
        self::assertSame(1, (int) $this->connection->query("SELECT COUNT(*) FROM users WHERE role_key = 'admin'")->fetchColumn());
        self::assertSame('complete', (new PdoInstallationStateRepository($this->connection))->status());
        self::assertSame('private-media-pixels', file_get_contents($restoreTarget . '/modules/n3/media/data/master.webp'));
        self::assertSame('private-document', file_get_contents($restoreTarget . '/files/document.txt'));
        self::assertFileDoesNotExist($restoreTarget . '/logs/app.log');
        self::assertSame(0600, fileperms($restoreTarget . '/install/installed.lock') & 0777);
        try {
            $restoreService->restore($created['id'], $this->root . '/second-restore', false);
            self::fail('Restore must reject a database that already contains managed tables.');
        } catch (BackupException $exception) {
            self::assertStringContainsString('clean target', $exception->getMessage());
        }

        $candidates = $service->pruneCandidates(30);
        self::assertContains($old['id'], $candidates);
        self::assertNotContains($created['id'], $candidates);
        self::assertSame(1, $service->prune($candidates));
        self::assertDirectoryDoesNotExist($this->root . '/backups/' . $old['id']);

        $this->connection->exec('DROP TABLE `' . $this->database->tableNames->physical('site_events') . '`');
        try {
            $service->create();
            self::fail('A backup must refuse an installation with missing required Core tables.');
        } catch (BackupException $exception) {
            self::assertStringContainsString('Core tables are missing', $exception->getMessage());
        }

        $databaseArtifact = $this->root . '/backups/' . $created['id'] . '/database.n3enc';
        $handle = fopen($databaseArtifact, 'r+b');
        self::assertIsResource($handle);
        fseek($handle, 20);
        fwrite($handle, "\xff");
        fclose($handle);
        $this->expectException(BackupException::class);
        $service->verify($created['id']);
    }

    private function dropManagedTables(): void
    {
        $this->connection->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($this->database->tableNames->managedTables() as $table) {
                if (str_starts_with($table, $this->prefix)) {
                    $this->connection->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
                }
            }
        } finally {
            $this->connection->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || !is_dir($path) || is_link($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
