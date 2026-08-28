<?php

declare(strict_types=1);

namespace N3\App\Identity;

final class AuthenticationService
{
    private string $fallbackHash;

    public function __construct(
        private readonly IdentityValidator $validator,
        private readonly UserRepository $users,
        private readonly RateLimiter $rateLimiter,
        private readonly SecurityEventRecorder $events,
    ) {
        $this->fallbackHash = password_hash('n3 constant fallback password value', PASSWORD_DEFAULT);
    }

    public function authenticate(string $email, string $password, string $ip, string $requestId): AuthenticationOutcome
    {
        $normalized = $this->validator->normalizeEmail($email);
        $ipAllowed = $this->rateLimiter->allow('login_ip', $ip, 50, 900);
        $emailAllowed = $this->rateLimiter->allow('login_email', $normalized, 10, 900);
        $user = $this->users->findByNormalizedEmail($normalized);
        $validPassword = password_verify($password, $user?->passwordHash ?? $this->fallbackHash);

        if (!$ipAllowed || !$emailAllowed || !$validPassword || $user === null) {
            $this->events->record('login', 'rejected', $normalized, $ip, $user?->id, $requestId);
            return new AuthenticationOutcome();
        }
        if (!$user->emailVerified || $user->status !== 'active') {
            $this->events->record('login', 'rejected', $normalized, $ip, $user->id, $requestId);
            return new AuthenticationOutcome(null, !$user->emailVerified && $user->status === 'pending_verification');
        }
        if (password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT)) {
            $this->users->updatePasswordHash($user->id, password_hash($password, PASSWORD_DEFAULT), false);
        }
        $this->users->recordSuccessfulLogin($user->id);
        $this->events->record('login', 'accepted', $normalized, $ip, $user->id, $requestId);

        return new AuthenticationOutcome($user);
    }
}
