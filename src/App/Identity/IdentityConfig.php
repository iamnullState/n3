<?php

declare(strict_types=1);

namespace N3\App\Identity;

use N3\Core\Config\Environment;
use RuntimeException;

final readonly class IdentityConfig
{
    public function __construct(
        public bool $registrationEnabled,
        public string $appUrl,
        public string $securityHashKey,
        public int $verificationTtl = 86400,
        public int $resetTtl = 1800,
        public int $sessionIdleTtl = 1800,
        public int $sessionAbsoluteTtl = 43200,
        public string $mailDriver = 'local_outbox',
        public bool $emailVerificationRequired = true,
    ) {
        if (!preg_match('#^https?://[^/]+$#', $appUrl)) {
            throw new RuntimeException('APP_URL must be an absolute URL without a trailing slash.');
        }

        if (strlen($securityHashKey) < 32) {
            throw new RuntimeException('SECURITY_HASH_KEY must contain at least 32 bytes.');
        }

        if ($verificationTtl < 300 || $verificationTtl > 604800
            || $resetTtl < 300 || $resetTtl > 86400
            || $sessionIdleTtl < 300 || $sessionIdleTtl > 86400
            || $sessionAbsoluteTtl < $sessionIdleTtl || $sessionAbsoluteTtl > 604800) {
            throw new RuntimeException('Identity lifetimes are outside their supported bounds.');
        }
    }

    public static function fromEnvironment(string $environment): self
    {
        $enabled = Environment::boolean('REGISTRATION_ENABLED', false);
        $driver = Environment::string('IDENTITY_MAIL_DRIVER', 'local_outbox');
        $verificationRequired = Environment::boolean('EMAIL_VERIFICATION_REQUIRED', true);

        if (!$verificationRequired && $environment === 'production') {
            throw new RuntimeException('EMAIL_VERIFICATION_REQUIRED may be disabled only in local or test environments.');
        }

        if ($enabled && $environment === 'production' && $driver === 'local_outbox') {
            throw new RuntimeException('Production registration requires a production mail adapter.');
        }

        return new self(
            $enabled,
            Environment::string('APP_URL', 'http://127.0.0.1:8000'),
            Environment::string('SECURITY_HASH_KEY'),
            self::integer('EMAIL_VERIFICATION_TTL', 86400),
            self::integer('PASSWORD_RESET_TTL', 1800),
            self::integer('SESSION_IDLE_TTL', 1800),
            self::integer('SESSION_ABSOLUTE_TTL', 43200),
            $driver,
            $verificationRequired,
        );
    }

    private static function integer(string $key, int $default): int
    {
        $value = Environment::string($key, (string) $default);

        if (!ctype_digit($value)) {
            throw new RuntimeException(sprintf('%s must be an integer.', $key));
        }

        return (int) $value;
    }
}
