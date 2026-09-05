<?php

declare(strict_types=1);

namespace N3\Core\Deployment;

use RuntimeException;

final class ProductionGuard
{
    /** @param array{environment: string, debug: bool} $app */
    public static function assertWebReady(string $root, array $app): void
    {
        if ($app['environment'] !== 'production') {
            return;
        }

        $violations = self::violations($root, $app, false);
        if ($violations !== []) {
            throw new RuntimeException('Production configuration is unsafe: ' . implode(', ', $violations) . '.');
        }
    }

    /**
     * @param array{environment: string, debug: bool} $app
     * @return list<string>
     */
    public static function violations(string $root, array $app, bool $allowMigrationCredentials): array
    {
        if ($app['environment'] !== 'production') {
            return ['APP_ENV must be production'];
        }

        $violations = [];
        if ($app['debug']) {
            $violations[] = 'APP_DEBUG must be false';
        }
        $appUrl = self::value('APP_URL');
        if ($appUrl === null || !self::isHttpsOrigin($appUrl)) {
            $violations[] = 'APP_URL must use HTTPS without a trailing slash';
        }
        $hashKey = self::value('SECURITY_HASH_KEY');
        if ($hashKey === null || strlen($hashKey) < 32 || str_starts_with($hashKey, 'replace-with-')) {
            $violations[] = 'SECURITY_HASH_KEY is missing or placeholder';
        }
        if (filter_var(self::value('EMAIL_VERIFICATION_REQUIRED') ?? 'true', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true) {
            $violations[] = 'EMAIL_VERIFICATION_REQUIRED must be true';
        }
        if (filter_var(self::value('REGISTRATION_ENABLED') ?? 'false', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false) {
            $violations[] = 'REGISTRATION_ENABLED must remain false until a production mail adapter exists';
        }
        if (filter_var(self::value('INSTALL_REOPEN') ?? 'false', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false) {
            $violations[] = 'INSTALL_REOPEN must be false';
        }
        if (self::value('INSTALL_TOKEN') !== null) {
            $violations[] = 'INSTALL_TOKEN must be removed after setup';
        }
        if (!$allowMigrationCredentials
            && (self::value('DB_MIGRATION_USER') !== null || self::value('DB_MIGRATION_PASSWORD') !== null)) {
            $violations[] = 'migration credentials must not be available to the web runtime';
        }
        if (self::value('BACKUP_ENCRYPTION_KEY') !== null) {
            $violations[] = 'backup encryption key must not be available to the web runtime';
        }
        if (self::value('DB_BACKUP_USER') !== null || self::value('DB_BACKUP_PASSWORD') !== null) {
            $violations[] = 'backup credentials must not be available to the web runtime';
        }
        $databaseHost = strtolower(self::value('DB_HOST') ?? '127.0.0.1');
        if (!in_array($databaseHost, ['127.0.0.1', 'localhost', '::1'], true)) {
            $violations[] = 'remote database hosts require a future TLS configuration';
        }
        $storage = realpath($root . '/storage');
        $public = realpath($root . '/public');
        if ($storage === false || !is_dir($storage) || !is_writable($storage)) {
            $violations[] = 'private storage must be writable';
        } elseif ($public !== false && str_starts_with($storage . DIRECTORY_SEPARATOR, $public . DIRECTORY_SEPARATOR)) {
            $violations[] = 'private storage must be outside the public document root';
        } elseif (((fileperms($storage) ?: 0) & 0007) !== 0) {
            $violations[] = 'private storage must not be accessible to other system users';
        }
        $lock = $root . '/storage/install/installed.lock';
        if (!is_file($lock) || is_link($lock) || ((fileperms($lock) ?: 0) & 0077) !== 0) {
            $violations[] = 'private installation lock is missing or too permissive';
        }

        return $violations;
    }

    private static function value(string $key): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private static function isHttpsOrigin(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && (($parts['path'] ?? '') === '');
    }
}
