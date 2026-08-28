<?php

declare(strict_types=1);

use N3\Core\Database\Migration;

return new class implements Migration {
    public function version(): string
    {
        return '202608270002_create_identity_security';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE email_verification_tokens ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'user_id BIGINT UNSIGNED NOT NULL, token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'expires_at TIMESTAMP(6) NOT NULL, consumed_at TIMESTAMP(6) NULL, revoked_at TIMESTAMP(6) NULL, '
            . 'created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'CONSTRAINT email_verification_token_unique UNIQUE (token_hash), '
            . 'CONSTRAINT email_verification_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, '
            . 'INDEX email_verification_user_index (user_id, expires_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $connection->exec(
            'CREATE TABLE rate_limit_buckets ('
            . 'action_key VARCHAR(64) NOT NULL, subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'window_start BIGINT UNSIGNED NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 1, '
            . 'updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), '
            . 'PRIMARY KEY (action_key, subject_hash, window_start), INDEX rate_limit_updated_index (updated_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $connection->exec(
            'CREATE TABLE security_events ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL, '
            . 'event_type VARCHAR(64) NOT NULL, outcome VARCHAR(32) NOT NULL, '
            . 'subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . 'ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, request_id CHAR(16) NULL, '
            . 'created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'CONSTRAINT security_event_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL, '
            . 'INDEX security_event_created_index (created_at), INDEX security_event_type_index (event_type, outcome)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE security_events');
        $connection->exec('DROP TABLE rate_limit_buckets');
        $connection->exec('DROP TABLE email_verification_tokens');
    }
};
