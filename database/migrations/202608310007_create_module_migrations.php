<?php

declare(strict_types=1);

use N3\Core\Database\Migration;

return new class implements Migration {
    public function version(): string
    {
        return '202608310007_create_module_migrations';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE module_migrations ('
            . 'module_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'migration_version VARCHAR(95) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'applied_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'PRIMARY KEY (module_id, migration_version), '
            . 'INDEX module_migrations_applied_index (applied_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(PDO $connection): void
    {
        if ((int) $connection->query('SELECT COUNT(*) FROM module_migrations')->fetchColumn() > 0) {
            throw new RuntimeException('Module migration history cannot be dropped after module migrations are applied.');
        }
        $connection->exec('DROP TABLE module_migrations');
    }
};
