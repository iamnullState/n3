<?php

declare(strict_types=1);

namespace N3\App\Identity;

use Closure;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\SessionStore;

final readonly class AuthSessionManager
{
    private Closure $clock;

    public function __construct(
        private SessionStore $session,
        private CsrfTokenManager $csrf,
        private UserRepository $users,
        private int $idleTtl,
        private int $absoluteTtl,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function login(IdentityUser $user): void
    {
        $now = ($this->clock)();
        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->put('_auth', [
            'user_id' => $user->id,
            'session_version' => $user->sessionVersion,
            'authenticated_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    public function current(): ?IdentityUser
    {
        $auth = $this->session->get('_auth');
        if (!is_array($auth)) {
            return null;
        }
        $now = ($this->clock)();
        $authenticatedAt = (int) ($auth['authenticated_at'] ?? 0);
        $lastSeenAt = (int) ($auth['last_seen_at'] ?? 0);
        $userId = (int) ($auth['user_id'] ?? 0);
        $sessionVersion = (int) ($auth['session_version'] ?? 0);
        if ($authenticatedAt <= 0 || $lastSeenAt <= 0
            || $now - $lastSeenAt > $this->idleTtl || $now - $authenticatedAt > $this->absoluteTtl) {
            $this->logout();
            return null;
        }
        $user = $this->users->findById($userId);
        if ($user === null || $user->status !== 'active' || !$user->emailVerified || $user->sessionVersion !== $sessionVersion) {
            $this->logout();
            return null;
        }
        $auth['last_seen_at'] = $now;
        $this->session->put('_auth', $auth);

        return $user;
    }

    public function logout(): void
    {
        $this->session->invalidate();
    }
}
