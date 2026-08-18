<?php
declare(strict_types=1);

namespace N3\Support;

use N3\Config;

final class Version
{
    private static ?string $current = null;

    public static function current(): string
    {
        if (self::$current !== null) return self::$current;
        $value = trim((string)@file_get_contents(Config::projectRoot() . '/VERSION'));
        self::$current = preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $value) ? $value : 'dev';
        return self::$current;
    }
}

