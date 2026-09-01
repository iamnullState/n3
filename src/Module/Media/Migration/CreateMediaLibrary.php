<?php

declare(strict_types=1);

namespace N3\Module\Media\Migration;

use N3\Core\Module\ModuleMigration;
use N3\Module\Media\MediaSchema;
use PDO;

final class CreateMediaLibrary implements ModuleMigration
{
    public function moduleId(): string
    {
        return MediaSchema::MODULE_ID;
    }

    public function version(): string
    {
        return '202609010002_create_media_library';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(sprintf(
            'CREATE TABLE `%s` ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'label VARCHAR(120) NOT NULL, '
            . 'width MEDIUMINT UNSIGNED NOT NULL, '
            . 'height MEDIUMINT UNSIGNED NOT NULL, '
            . 'byte_size INT UNSIGNED NOT NULL, '
            . 'sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'created_at DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (id), UNIQUE KEY uq_media_public_id (public_id), '
            . 'KEY idx_media_created (created_at, id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            MediaSchema::assetsTable(),
        ));
        $connection->exec(sprintf(
            'CREATE TABLE `%s` ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . 'event_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'asset_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . 'occurred_at DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (id), KEY idx_media_events_time (occurred_at, id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            MediaSchema::eventsTable(),
        ));
        $connection->exec(sprintf(
            'CREATE TABLE `%s` ('
            . 'subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'window_start BIGINT UNSIGNED NOT NULL, attempts SMALLINT UNSIGNED NOT NULL, '
            . 'PRIMARY KEY (subject_hash, window_start), KEY idx_media_limits_window (window_start)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            MediaSchema::limitsTable(),
        ));
    }
}
