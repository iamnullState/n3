<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\App\Identity\IdentityValidator;
use PHPUnit\Framework\TestCase;

final class IdentityValidatorTest extends TestCase
{
    public function testItNormalizesEmailAndAcceptsAPassphrase(): void
    {
        $validator = new IdentityValidator();

        self::assertSame('person@example.test', $validator->normalizeEmail(' Person@Example.TEST '));
        self::assertSame([], $validator->registrationErrors(
            'Ada Lovelace',
            'Person@Example.test',
            'correct horse battery staple',
            'correct horse battery staple',
        ));
    }

    public function testItRejectsInvalidFieldsWithoutEchoingPasswords(): void
    {
        $errors = (new IdentityValidator())->registrationErrors('A', 'bad', 'short', 'different');

        self::assertArrayHasKey('display_name', $errors);
        self::assertArrayHasKey('email', $errors);
        self::assertArrayHasKey('password', $errors);
        self::assertArrayHasKey('password_confirmation', $errors);
        self::assertStringNotContainsString('short', implode(' ', $errors));
        self::assertStringNotContainsString('different', implode(' ', $errors));
    }
}
