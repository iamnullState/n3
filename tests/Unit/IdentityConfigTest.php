<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\App\Identity\IdentityConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IdentityConfigTest extends TestCase
{
    public function testProductionRegistrationRejectsTheLocalOutbox(): void
    {
        $_ENV['REGISTRATION_ENABLED'] = 'true';
        $_ENV['IDENTITY_MAIL_DRIVER'] = 'local_outbox';
        $_ENV['APP_URL'] = 'https://cms.example.test';
        $_ENV['SECURITY_HASH_KEY'] = str_repeat('k', 32);

        try {
            $this->expectException(RuntimeException::class);
            IdentityConfig::fromEnvironment('production');
        } finally {
            unset(
                $_ENV['REGISTRATION_ENABLED'],
                $_ENV['IDENTITY_MAIL_DRIVER'],
                $_ENV['APP_URL'],
                $_ENV['SECURITY_HASH_KEY'],
            );
        }
    }
}
