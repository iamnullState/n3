<?php
declare(strict_types=1);

return [
    'name' => 'add plugin enablement overrides',
    'app_version' => '0.3.0',
    'up' => static function (\PDO $database): void {
        $database->exec(<<<'SQL'
            CREATE TABLE plugin_enablement_overrides (
                plugin_id TEXT PRIMARY KEY,
                enabled INTEGER NOT NULL CHECK(enabled IN (0, 1)),
                updated_by INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE CASCADE
            )
        SQL);
    },
];
