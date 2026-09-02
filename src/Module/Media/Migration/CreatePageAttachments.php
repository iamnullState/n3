<?php

declare(strict_types=1);

namespace N3\Module\Media\Migration;

use N3\Core\Module\ModuleMigration;
use N3\Module\Media\MediaSchema;
use PDO;

final class CreatePageAttachments implements ModuleMigration
{
    public function moduleId(): string
    {
        return MediaSchema::MODULE_ID;
    }

    public function version(): string
    {
        return '202609010003_create_page_attachments';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(sprintf(
            'CREATE TABLE `%s` ('
            . 'page_id BIGINT UNSIGNED NOT NULL, '
            . 'asset_public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'alt_text VARCHAR(300) NOT NULL, '
            . 'created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (page_id), KEY idx_page_media_asset (asset_public_id, page_id), '
            . 'CONSTRAINT chk_page_media_alt CHECK (CHAR_LENGTH(alt_text) BETWEEN 2 AND 300), '
            . 'CONSTRAINT fk_page_media_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE RESTRICT, '
            . 'CONSTRAINT fk_page_media_asset FOREIGN KEY (asset_public_id) REFERENCES `%s`(public_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            MediaSchema::attachmentsTable(),
            MediaSchema::assetsTable(),
        ));
    }
}
