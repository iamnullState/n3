<?php
declare(strict_types=1);

return [
    'name' => 'add plugin migration ledger',
    'app_version' => '0.5.0',
    'up' => static function (\PDO $database): void {
        $database->exec(<<<'SQL'
            CREATE TABLE plugin_migrations (
                plugin_id TEXT NOT NULL,
                migration INTEGER NOT NULL,
                name TEXT NOT NULL,
                checksum TEXT NOT NULL,
                applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(plugin_id, migration)
            );
            CREATE INDEX idx_plugin_migrations_applied ON plugin_migrations(plugin_id, applied_at);
        SQL);
    },
];
