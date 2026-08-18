<?php
declare(strict_types=1);

return [
    'name' => 'add extensions references and collaboration',
    'app_version' => '0.3.0',
    'up' => static function (\PDO $database): void {
        $database->exec('ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0');
        $database->exec('ALTER TABLE spaces ADD COLUMN owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL');
        $database->exec('UPDATE users SET is_admin = 1 WHERE id = (SELECT MIN(id) FROM users)');
        $database->exec('UPDATE spaces SET owner_id = (SELECT MIN(id) FROM users) WHERE owner_id IS NULL');
        $database->exec(<<<'SQL'
            CREATE TABLE page_references (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id INTEGER NOT NULL,
                label TEXT NOT NULL,
                url TEXT NOT NULL,
                position INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(page_id) REFERENCES pages(id) ON DELETE CASCADE
            );
            CREATE INDEX idx_page_references_page ON page_references(page_id, position, id);
            CREATE TABLE resource_shares (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                resource_type TEXT NOT NULL CHECK(resource_type IN ('space', 'page')),
                resource_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role TEXT NOT NULL CHECK(role IN ('viewer', 'editor')),
                granted_by INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(resource_type, resource_id, user_id),
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY(granted_by) REFERENCES users(id) ON DELETE CASCADE
            );
            CREATE INDEX idx_resource_shares_user ON resource_shares(user_id, resource_type, resource_id);
            CREATE INDEX idx_resource_shares_resource ON resource_shares(resource_type, resource_id);
            CREATE TRIGGER delete_page_resource_shares
            AFTER DELETE ON pages
            BEGIN
                DELETE FROM resource_shares WHERE resource_type = 'page' AND resource_id = OLD.id;
            END;
            CREATE TRIGGER delete_space_resource_shares
            AFTER DELETE ON spaces
            BEGIN
                DELETE FROM resource_shares WHERE resource_type = 'space' AND resource_id = OLD.id;
            END;
        SQL);
    },
];
