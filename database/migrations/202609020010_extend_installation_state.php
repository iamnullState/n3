<?php

declare(strict_types=1);

use N3\Core\Database\Migration;

return new class implements Migration {
    public function version(): string
    {
        return '202609020010_extend_installation_state';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            "ALTER TABLE installation_state "
            . "ADD install_status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending_admin' AFTER table_prefix, "
            . 'ADD completed_at TIMESTAMP(6) NULL AFTER created_at, '
            . "ADD CONSTRAINT installation_state_status_check CHECK (install_status IN ('pending_admin', 'complete'))",
        );
        $connection->exec(
            "UPDATE installation_state SET install_status = 'complete', completed_at = CURRENT_TIMESTAMP(6) "
            . "WHERE id = 1 AND EXISTS (SELECT 1 FROM users WHERE role_key = 'admin' LIMIT 1)",
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE installation_state DROP CONSTRAINT installation_state_status_check, '
            . 'DROP COLUMN completed_at, DROP COLUMN install_status',
        );
    }
};
