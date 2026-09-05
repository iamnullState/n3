<?php

declare(strict_types=1);

namespace N3\Core\Backup;

use N3\Core\Config\Environment;

final readonly class BackupConfig
{
    public function __construct(
        public string $path,
        private string $masterKey,
        public int $retentionDays,
        public string $dumpBinary = 'mariadb-dump',
        public string $clientBinary = 'mariadb',
    ) {
        if ($path === '' || $path[0] !== '/' || rtrim($path, '/') === '' || str_contains($path, "\0")) {
            throw new BackupException('BACKUP_PATH must be an absolute private path.');
        }
        if (strlen($masterKey) !== 32) {
            throw new BackupException('BACKUP_ENCRYPTION_KEY must decode to exactly 32 bytes.');
        }
        if ($retentionDays < 1 || $retentionDays > 3650) {
            throw new BackupException('BACKUP_RETENTION_DAYS must be between 1 and 3650.');
        }
        foreach ([$dumpBinary, $clientBinary] as $binary) {
            if (preg_match('#^(?:[A-Za-z0-9_.-]+|/[A-Za-z0-9_./-]+)$#D', $binary) !== 1
                || str_contains($binary, '..')) {
                throw new BackupException('MariaDB backup binary names must be command names or absolute safe paths.');
            }
        }
    }

    public static function fromEnvironment(string $root): self
    {
        if (!extension_loaded('sodium')) {
            throw new BackupException('The sodium PHP extension is required for encrypted backups.');
        }
        $encoded = Environment::string('BACKUP_ENCRYPTION_KEY');
        $key = base64_decode($encoded, true);
        if (!is_string($key)) {
            throw new BackupException('BACKUP_ENCRYPTION_KEY must be valid base64.');
        }
        $retention = filter_var(
            Environment::string('BACKUP_RETENTION_DAYS', '30'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 3650]],
        );
        if ($retention === false) {
            throw new BackupException('BACKUP_RETENTION_DAYS must be between 1 and 3650.');
        }

        return new self(
            Environment::string('BACKUP_PATH', $root . '/storage/backups'),
            $key,
            $retention,
            Environment::string('MARIADB_DUMP_BINARY', 'mariadb-dump'),
            Environment::string('MARIADB_CLIENT_BINARY', 'mariadb'),
        );
    }

    public function encryptionKey(): string
    {
        return sodium_crypto_generichash('n3-backup-encryption-v1', $this->masterKey, 32);
    }

    public function authenticationKey(): string
    {
        return sodium_crypto_generichash('n3-backup-authentication-v1', $this->masterKey, 32);
    }
}
