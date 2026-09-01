<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\App\Identity\AuthSessionManager;
use N3\App\Identity\IdentityUser;
use N3\App\Identity\IdentityPrincipalProvider;
use N3\App\Identity\UserRepository;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\ArraySessionStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthSessionManagerTest extends TestCase
{
    #[DataProvider('expiryTimes')]
    public function testIdleAndAbsoluteSessionExpiry(int $idle, int $absolute, int $later): void
    {
        $now = 1_000;
        $user = new IdentityUser(7, 'Member', 'member@example.test', 'member@example.test', 'hash', 'active', 'member', true, 1);
        $repository = new SessionTestUserRepository($user);
        $session = new ArraySessionStore();
        $manager = new AuthSessionManager(
            $session,
            new CsrfTokenManager($session),
            $repository,
            $idle,
            $absolute,
            static function () use (&$now): int { return $now; },
        );
        $manager->login($user);
        $now = $later;

        self::assertNull($manager->current());
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function expiryTimes(): iterable
    {
        yield 'idle timeout' => [30, 500, 1_031];
        yield 'absolute timeout' => [500, 60, 1_061];
    }

    public function testSessionVersionMismatchInvalidatesAuthentication(): void
    {
        $user = new IdentityUser(7, 'Member', 'member@example.test', 'member@example.test', 'hash', 'active', 'member', true, 1);
        $repository = new SessionTestUserRepository($user);
        $session = new ArraySessionStore();
        $csrf = new CsrfTokenManager($session);
        $manager = new AuthSessionManager($session, $csrf, $repository, 1800, 43200);
        $manager->login($user);
        $repository->user = new IdentityUser(7, 'Member', 'member@example.test', 'member@example.test', 'hash', 'active', 'member', true, 2);

        self::assertNull($manager->current());
    }

    public function testIdentityPrincipalExposesAuthorityWithoutAccountIdentifier(): void
    {
        $user = new IdentityUser(7, 'Member', 'member@example.test', 'member@example.test', 'hash', 'active', 'member', true, 1);
        $repository = new SessionTestUserRepository($user);
        $session = new ArraySessionStore();
        $manager = new AuthSessionManager($session, new CsrfTokenManager($session), $repository, 1800, 43200);
        $manager->login($user);

        $principal = (new IdentityPrincipalProvider($manager))->current();
        self::assertNotNull($principal);
        self::assertSame('member', $principal->authority);
        self::assertObjectNotHasProperty('id', $principal);
    }
}

final class SessionTestUserRepository implements UserRepository
{
    public function __construct(public ?IdentityUser $user) {}
    public function normalizedEmailExists(string $normalizedEmail): bool { return false; }
    public function findByNormalizedEmail(string $normalizedEmail): ?IdentityUser { return $this->user; }
    public function findById(int $userId): ?IdentityUser { return $this->user?->id === $userId ? $this->user : null; }
    public function createPending(string $displayName, string $email, string $normalizedEmail, string $passwordHash): int { return 1; }
    public function markEmailVerified(int $userId): void {}
    public function updatePasswordHash(int $userId, string $passwordHash, bool $invalidateSessions): void {}
    public function recordSuccessfulLogin(int $userId): void {}
    public function updateStatus(int $userId, string $status): void {}
    public function updateAuthority(int $userId, string $authority): void {}
    public function adminExists(): bool { return false; }
    public function createAdmin(string $displayName, string $email, string $normalizedEmail, string $passwordHash): int { return 1; }
}
