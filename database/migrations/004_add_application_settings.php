<?php
declare(strict_types=1);

return [
    'name' => 'add whitelabel application settings',
    'app_version' => '0.4.0',
    'up' => static function (\PDO $database): void {
        $database->exec(<<<'SQL'
            CREATE TABLE app_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
        SQL);
    },
];
