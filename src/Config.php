<?php
declare(strict_types=1);

namespace N3;

final class Config
{
    private static array $runtimeSettings = [];

    public static function setRuntimeSettings(array $settings): void
    {
        self::$runtimeSettings = $settings;
    }
    public static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function timezone(): string
    {
        return getenv('APP_TIMEZONE') ?: 'UTC';
    }

    public static function dataDir(): string
    {
        return rtrim(getenv('DATA_DIR') ?: '/var/www/data', '/');
    }

    public static function pluginDir(): string
    {
        $configured = getenv('N3_PLUGIN_DIR');
        if ($configured) return rtrim($configured, '/');
        return getenv('DATA_DIR') ? self::dataDir() . '/plugins' : self::projectRoot() . '/plugins';
    }

    public static function backupDir(): string
    {
        return rtrim(getenv('BACKUP_DIR') ?: self::dataDir() . '/backups', '/');
    }

    public static function appName(): string
    {
        return self::$runtimeSettings['brandName'] ?? (getenv('APP_NAME') ?: 'n3');
    }

    public static function appUrl(): string
    {
        return rtrim(self::$runtimeSettings['appUrl'] ?? (getenv('APP_URL') ?: 'http://localhost:8786'), '/');
    }

    public static function publicHttps(): bool
    {
        return strtolower((string)parse_url(self::appUrl(), PHP_URL_SCHEME)) === 'https';
    }

    public static function trustedProxyIps(): array
    {
        $values = array_map('trim', explode(',', getenv('TRUSTED_PROXY_IPS') ?: ''));
        return array_values(array_filter($values, static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false));
    }

    public static function isTrustedProxy(string $ip): bool
    {
        return in_array($ip, self::trustedProxyIps(), true);
    }
}
