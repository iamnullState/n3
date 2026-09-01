<?php

declare(strict_types=1);

use N3\Core\Database\Migration;

return new class implements Migration {
    public function version(): string
    {
        return '202608310006_create_webhook_receipts';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE webhook_receipts ('
            . 'source_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'delivery_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'received_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'expires_at TIMESTAMP(6) NOT NULL, '
            . 'PRIMARY KEY (source_key, delivery_hash), '
            . 'INDEX webhook_receipts_expiry_index (expires_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE webhook_receipts');
    }
};
