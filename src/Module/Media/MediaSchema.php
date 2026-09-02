<?php

declare(strict_types=1);

namespace N3\Module\Media;

use N3\Core\Module\ModuleResourcePolicy;

final class MediaSchema
{
    public const MODULE_ID = 'n3/media';

    public static function assetsTable(): string
    {
        return ModuleResourcePolicy::schemaPrefix(self::MODULE_ID) . 'assets';
    }

    public static function eventsTable(): string
    {
        return ModuleResourcePolicy::schemaPrefix(self::MODULE_ID) . 'events';
    }

    public static function limitsTable(): string
    {
        return ModuleResourcePolicy::schemaPrefix(self::MODULE_ID) . 'upload_limits';
    }

    public static function attachmentsTable(): string
    {
        return ModuleResourcePolicy::schemaPrefix(self::MODULE_ID) . 'page_attachments';
    }
}
