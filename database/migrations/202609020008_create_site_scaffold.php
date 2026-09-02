<?php

declare(strict_types=1);

use N3\Core\Database\Migration;

return new class implements Migration {
    public function version(): string
    {
        return '202609020008_create_site_scaffold';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE site_settings ('
            . 'id TINYINT UNSIGNED NOT NULL PRIMARY KEY, '
            . 'site_name VARCHAR(100) NOT NULL, tagline VARCHAR(200) NOT NULL, '
            . 'contact_email VARCHAR(254) NOT NULL, '
            . 'primary_color CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'logo_path VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . 'lock_version INT UNSIGNED NOT NULL DEFAULT 1, updated_by BIGINT UNSIGNED NOT NULL, '
            . 'created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), '
            . 'CONSTRAINT site_settings_singleton CHECK (id = 1), '
            . "CONSTRAINT site_settings_color CHECK (primary_color REGEXP '^#[0-9A-F]{6}$'), "
            . 'CONSTRAINT site_settings_actor_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $connection->exec(
            'CREATE TABLE site_navigation_items ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'page_id BIGINT UNSIGNED NOT NULL, label VARCHAR(80) NOT NULL, '
            . 'position SMALLINT UNSIGNED NOT NULL, is_visible TINYINT(1) NOT NULL DEFAULT 1, '
            . 'created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), '
            . 'CONSTRAINT site_navigation_page_unique UNIQUE (page_id), '
            . 'CONSTRAINT site_navigation_position_unique UNIQUE (position), '
            . 'CONSTRAINT site_navigation_page_fk FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE RESTRICT, '
            . 'INDEX site_navigation_public_index (is_visible, position)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $connection->exec(
            'CREATE TABLE site_events ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'event_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'actor_user_id BIGINT UNSIGNED NOT NULL, request_id CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . 'occurred_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'CONSTRAINT site_event_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT, '
            . 'INDEX site_event_time_index (occurred_at, id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE site_events');
        $connection->exec('DROP TABLE site_navigation_items');
        $connection->exec('DROP TABLE site_settings');
    }
};
