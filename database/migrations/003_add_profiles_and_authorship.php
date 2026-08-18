<?php
declare(strict_types=1);

return [
    'name' => 'add profiles and page authorship',
    'app_version' => '0.4.0',
    'up' => static function (\PDO $database): void {
        $database->exec(<<<'SQL'
            ALTER TABLE users ADD COLUMN display_name TEXT NOT NULL DEFAULT '';
            ALTER TABLE users ADD COLUMN biography TEXT NOT NULL DEFAULT '';
            ALTER TABLE users ADD COLUMN profile_slug TEXT;
            ALTER TABLE users ADD COLUMN profile_visibility TEXT NOT NULL DEFAULT 'private'
                CHECK(profile_visibility IN ('private', 'members', 'public'));
            ALTER TABLE users ADD COLUMN avatar_reference TEXT;

            ALTER TABLE pages ADD COLUMN author_id INTEGER REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE pages ADD COLUMN last_editor_id INTEGER REFERENCES users(id) ON DELETE SET NULL;
            ALTER TABLE pages ADD COLUMN first_published_at TEXT;

            CREATE UNIQUE INDEX idx_users_profile_slug
                ON users(profile_slug COLLATE NOCASE)
                WHERE profile_slug IS NOT NULL;
            CREATE INDEX idx_pages_author ON pages(author_id, is_deleted, updated_at DESC);
            CREATE INDEX idx_pages_last_editor ON pages(last_editor_id);

            UPDATE users SET display_name = username WHERE display_name = '';
            UPDATE pages
            SET author_id = (SELECT spaces.owner_id FROM spaces WHERE spaces.id = pages.space_id),
                last_editor_id = (SELECT spaces.owner_id FROM spaces WHERE spaces.id = pages.space_id)
            WHERE EXISTS (
                SELECT 1 FROM spaces
                WHERE spaces.id = pages.space_id AND spaces.owner_id IS NOT NULL
            );
            UPDATE pages
            SET first_published_at = updated_at
            WHERE is_public = 1 AND first_published_at IS NULL;
        SQL);

        $users = $database->query('SELECT id, username FROM users ORDER BY id')->fetchAll();
        $updateSlug = $database->prepare('UPDATE users SET profile_slug = ? WHERE id = ?');
        foreach ($users as $user) {
            $base = mb_strtolower(trim((string)$user['username']));
            $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
            $base = trim((string)preg_replace('/[^a-z0-9]+/', '-', $base), '-');
            if ($base === '') $base = 'user';
            $updateSlug->execute([$base . '-' . (int)$user['id'], (int)$user['id']]);
        }
    },
];
