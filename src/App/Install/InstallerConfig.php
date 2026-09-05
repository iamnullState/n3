<?php

declare(strict_types=1);

namespace N3\App\Install;

use N3\Core\Config\Environment;
use RuntimeException;

final readonly class InstallerConfig
{
    public function __construct(
        public string $environment,
        public string $appUrl,
        #[\SensitiveParameter] private string $securityHashKey,
        #[\SensitiveParameter] private string $installToken,
        public bool $reopen = false,
    ) {
        if (!in_array($environment, ['local', 'test', 'production'], true)) {
            throw new RuntimeException('APP_ENV is invalid.');
        }
        if (!preg_match('#^https?://[^/]+$#D', $appUrl)) {
            throw new RuntimeException('APP_URL must be an absolute URL without a trailing slash.');
        }
        if (strlen($securityHashKey) < 32 || str_starts_with($securityHashKey, 'replace-with-')) {
            throw new RuntimeException('SECURITY_HASH_KEY must be replaced with at least 32 random bytes.');
        }
        if (strlen($installToken) < 32 || str_starts_with($installToken, 'replace-with-')) {
            throw new RuntimeException('INSTALL_TOKEN must be replaced with at least 32 random bytes.');
        }
    }

    public static function fromEnvironment(string $environment): self
    {
        return new self(
            $environment,
            Environment::string('APP_URL', 'http://127.0.0.1:8000'),
            Environment::string('SECURITY_HASH_KEY'),
            Environment::string('INSTALL_TOKEN'),
            Environment::boolean('INSTALL_REOPEN', false),
        );
    }

    public function authorizes(mixed $candidate): bool
    {
        return is_string($candidate) && hash_equals($this->installToken, $candidate);
    }
}
