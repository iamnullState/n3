<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Backup\BackupConfig;
use N3\Core\Backup\BackupException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupConfigTest extends TestCase
{
    public function testValidConfigurationRetainsPrivateKeyWithoutExposingItAsAProperty(): void
    {
        $key = random_bytes(32);
        $config = new BackupConfig('/private/backups', $key, 30, '/usr/bin/mariadb-dump', 'mariadb');

        self::assertSame('/private/backups', $config->path);
        self::assertSame(30, $config->retentionDays);
        self::assertNotSame($key, $config->encryptionKey());
        self::assertNotSame($config->encryptionKey(), $config->authenticationKey());
        self::assertArrayNotHasKey('masterKey', get_object_vars($config));
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'relative path' => ['relative', str_repeat('k', 32), 30, 'mariadb-dump'];
        yield 'filesystem root' => ['/', str_repeat('k', 32), 30, 'mariadb-dump'];
        yield 'short key' => ['/private', 'short', 30, 'mariadb-dump'];
        yield 'zero retention' => ['/private', str_repeat('k', 32), 0, 'mariadb-dump'];
        yield 'unsafe binary' => ['/private', str_repeat('k', 32), 30, '../mariadb-dump'];
    }

    #[DataProvider('invalidConfiguration')]
    public function testInvalidConfigurationFailsClosed(string $path, string $key, int $days, string $binary): void
    {
        $this->expectException(BackupException::class);
        new BackupConfig($path, $key, $days, $binary);
    }
}
