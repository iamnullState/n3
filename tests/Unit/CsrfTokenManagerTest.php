<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\ArraySessionStore;
use PHPUnit\Framework\TestCase;

final class CsrfTokenManagerTest extends TestCase
{
    public function testTokensAreSessionBoundIntentBoundAndRotatable(): void
    {
        $session = new ArraySessionStore();
        $tokens = new CsrfTokenManager($session);
        $register = $tokens->token('register');

        self::assertTrue($tokens->verify('register', $register));
        self::assertFalse($tokens->verify('logout', $register));
        self::assertFalse($tokens->verify('register', 'not-a-token'));

        $tokens->rotate();

        self::assertFalse($tokens->verify('register', $register));
    }
}
