<?php

declare(strict_types=1);

use N3\Core\Database\Migration;

return new class implements Migration {
    public function version(): string
    {
        return '202608270003_create_authentication_recovery';
    }

    public function up(PDO $connection): void
    {
        $connection->exec('ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER email_verified_at');
        $connection->exec("ALTER TABLE users ADD CONSTRAINT users_role_key_check CHECK (role_key IN ('admin', 'member'))");
        $connection->exec('CREATE INDEX users_role_index ON users (role_key)');
        $connection->exec(
            'CREATE TABLE password_reset_tokens ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'user_id BIGINT UNSIGNED NOT NULL, token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'expires_at TIMESTAMP(6) NOT NULL, consumed_at TIMESTAMP(6) NULL, revoked_at TIMESTAMP(6) NULL, '
            . 'created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'CONSTRAINT password_reset_token_unique UNIQUE (token_hash), '
            . 'CONSTRAINT password_reset_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, '
            . 'INDEX password_reset_user_index (user_id, expires_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE password_reset_tokens');
        $connection->exec('DROP INDEX users_role_index ON users');
        $connection->exec('ALTER TABLE users DROP CONSTRAINT users_role_key_check');
        $connection->exec('ALTER TABLE users DROP COLUMN session_version');
    }
};
