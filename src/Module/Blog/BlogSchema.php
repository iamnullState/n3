<?php

declare(strict_types=1);

namespace N3\Module\Blog;

use N3\Core\Module\ModuleResourcePolicy;

final class BlogSchema
{
    public const MODULE_ID = 'n3/blog';

    public static function postsTable(): string
    {
        return ModuleResourcePolicy::schemaPrefix(self::MODULE_ID) . 'posts';
    }

    public static function eventsTable(): string
    {
        return ModuleResourcePolicy::schemaPrefix(self::MODULE_ID) . 'events';
    }
}
