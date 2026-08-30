<?php

declare(strict_types=1);

use N3\Core\Database\Migration;

return new class implements Migration {
    public function version(): string
    {
        return '202608300004_create_pages';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE pages ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'title VARCHAR(200) NOT NULL, '
            . 'slug VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'excerpt VARCHAR(500) NULL, body MEDIUMTEXT NOT NULL, '
            . "status VARCHAR(16) NOT NULL DEFAULT 'draft', published_at TIMESTAMP(6) NULL, "
            . 'author_id BIGINT UNSIGNED NOT NULL, updated_by BIGINT UNSIGNED NOT NULL, '
            . 'lock_version INT UNSIGNED NOT NULL DEFAULT 1, '
            . 'created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), '
            . 'CONSTRAINT pages_slug_unique UNIQUE (slug), '
            . "CONSTRAINT pages_status_check CHECK (status IN ('draft', 'published')), "
            . 'CONSTRAINT pages_author_fk FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT, '
            . 'CONSTRAINT pages_updated_by_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT, '
            . 'INDEX pages_public_index (status, slug), INDEX pages_updated_index (updated_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $connection->exec(
            'CREATE TABLE content_events ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, page_id BIGINT UNSIGNED NOT NULL, '
            . 'actor_user_id BIGINT UNSIGNED NOT NULL, event_type VARCHAR(32) NOT NULL, '
            . 'from_status VARCHAR(16) NULL, to_status VARCHAR(16) NULL, request_id CHAR(16) NULL, '
            . 'created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'CONSTRAINT content_event_page_fk FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE RESTRICT, '
            . 'CONSTRAINT content_event_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT, '
            . 'INDEX content_event_page_index (page_id, created_at), INDEX content_event_created_index (created_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE content_events');
        $connection->exec('DROP TABLE pages');
    }
};
