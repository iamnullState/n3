<?php

declare(strict_types=1);

namespace N3\Module\Blog\Migration;

use N3\Core\Module\ModuleMigration;
use N3\Module\Blog\BlogSchema;
use PDO;

final class CreateBlogContent implements ModuleMigration
{
    public function moduleId(): string
    {
        return BlogSchema::MODULE_ID;
    }

    public function version(): string
    {
        return '202609020001_create_blog_content';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(sprintf(
            'CREATE TABLE `%s` ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'title VARCHAR(200) NOT NULL, slug VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'excerpt VARCHAR(500) NULL, body MEDIUMTEXT NOT NULL, '
            . "status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft', "
            . 'author_id BIGINT UNSIGNED NOT NULL, updated_by BIGINT UNSIGNED NOT NULL, '
            . 'lock_version INT UNSIGNED NOT NULL DEFAULT 1, published_at DATETIME(6) NULL, '
            . 'created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), '
            . 'UNIQUE KEY uq_blog_slug (slug), '
            . 'KEY idx_blog_public (status, published_at, id), KEY idx_blog_admin (updated_at, id), '
            . "CONSTRAINT ck_blog_status CHECK (status IN ('draft','published')), "
            . "CONSTRAINT ck_blog_publication CHECK ((status = 'draft' AND published_at IS NULL) OR (status = 'published' AND published_at IS NOT NULL)), "
            . 'CONSTRAINT fk_blog_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT, '
            . 'CONSTRAINT fk_blog_editor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            BlogSchema::postsTable(),
        ));
        $connection->exec(sprintf(
            'CREATE TABLE `%s` ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id BIGINT UNSIGNED NOT NULL, '
            . 'actor_user_id BIGINT UNSIGNED NOT NULL, '
            . 'event_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'from_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . 'to_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'request_id CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . 'occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'KEY idx_blog_events_post (post_id, id), KEY idx_blog_events_time (occurred_at, id), '
            . "CONSTRAINT ck_blog_event_type CHECK (event_type IN ('created','updated','published','unpublished')), "
            . "CONSTRAINT ck_blog_event_from CHECK (from_status IS NULL OR from_status IN ('draft','published')), "
            . "CONSTRAINT ck_blog_event_to CHECK (to_status IN ('draft','published')), "
            . 'CONSTRAINT fk_blog_event_post FOREIGN KEY (post_id) REFERENCES `%s`(id) ON DELETE RESTRICT, '
            . 'CONSTRAINT fk_blog_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            BlogSchema::eventsTable(),
            BlogSchema::postsTable(),
        ));
    }
}
