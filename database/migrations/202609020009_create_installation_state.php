<?php

declare(strict_types=1);

use N3\Core\Database\Migration;
use N3\Core\Database\TablePrefixedPdo;

return new class implements Migration {
    public function version(): string
    {
        return '202609020009_create_installation_state';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE installation_state ('
            . 'id TINYINT UNSIGNED NOT NULL PRIMARY KEY, '
            . 'table_prefix VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'CONSTRAINT installation_state_singleton CHECK (id = 1)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $prefix = $connection instanceof TablePrefixedPdo ? $connection->tableNames()->prefix() : '';
        $statement = $connection->prepare(
            'INSERT INTO installation_state (id, table_prefix) VALUES (1, :table_prefix)',
        );
        $statement->execute(['table_prefix' => $prefix]);
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE installation_state');
    }
};
