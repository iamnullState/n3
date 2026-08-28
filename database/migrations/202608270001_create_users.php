<?php

declare(strict_types=1);

use N3\Core\Database\Migration;

return new class implements Migration {
    public function version(): string
    {
        return '202608270001_create_users';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE users ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'display_name VARCHAR(100) NOT NULL, '
            . 'email VARCHAR(254) NOT NULL, '
            . 'email_normalized VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, '
            . 'password_hash VARCHAR(255) NOT NULL, '
            . "account_status VARCHAR(32) NOT NULL DEFAULT 'pending_verification', "
            . "role_key VARCHAR(64) NOT NULL DEFAULT 'member', "
            . 'email_verified_at TIMESTAMP(6) NULL, '
            . 'last_login_at TIMESTAMP(6) NULL, '
            . 'created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) '
            . 'ON UPDATE CURRENT_TIMESTAMP(6), '
            . 'CONSTRAINT users_email_normalized_unique UNIQUE (email_normalized), '
            . "CONSTRAINT users_account_status_check CHECK (account_status IN ('pending_verification', 'active', 'disabled'))"
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE users');
    }
};
