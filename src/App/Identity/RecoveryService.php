<?php

declare(strict_types=1);

namespace N3\App\Identity;

use N3\Core\Database\TransactionManager;

final readonly class RecoveryService
{
    public function __construct(
        private IdentityConfig $config,
        private IdentityValidator $validator,
        private UserRepository $users,
        private PasswordResetTokenRepository $tokens,
        private VerificationNotifier $notifier,
        private RateLimiter $rateLimiter,
        private SecurityEventRecorder $events,
        private TransactionManager $transactions,
    ) {
    }

    public function request(string $email, string $ip, string $requestId): bool
    {
        $normalized = $this->validator->normalizeEmail($email);
        $allowed = $this->rateLimiter->allow('reset_ip', $ip, 10, 3600)
            && $this->rateLimiter->allow('reset_email', $normalized, 3, 3600);
        $user = $this->users->findByNormalizedEmail($normalized);
        if ($allowed && $user !== null && $user->status === 'active' && $user->emailVerified) {
            $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $this->tokens->issue($user->id, hash('sha256', $rawToken), time() + $this->config->resetTtl);
            $this->notifier->sendPasswordReset(
                $user->email,
                $user->displayName,
                $this->config->appUrl . '/reset-password?token=' . rawurlencode($rawToken),
            );
        }
        $this->events->record('password_reset_requested', $allowed ? 'accepted' : 'throttled', $normalized, $ip, $user?->id, $requestId);

        return $allowed;
    }

    /** @return array<string, string> */
    public function reset(string $tokenHash, string $password, string $confirmation, string $ip, string $requestId): array
    {
        if (!$this->rateLimiter->allow('reset_completion_ip', $ip, 20, 3600)) {
            $this->events->record('password_reset', 'rate_limited', '', $ip, null, $requestId);
            return ['form' => 'That password reset link is invalid or expired.'];
        }
        $errors = $this->validator->passwordErrors($password, $confirmation);
        if ($errors !== []) {
            $this->events->record('password_reset', 'rejected_policy', '', $ip, null, $requestId);
            return $errors;
        }
        $userId = $this->transactions->run(function () use ($tokenHash, $password): ?int {
            $userId = $this->tokens->consume($tokenHash, time());
            if ($userId === null) {
                return null;
            }
            $this->users->updatePasswordHash($userId, password_hash($password, PASSWORD_DEFAULT), true);
            $this->tokens->revokeForUser($userId);

            return $userId;
        });
        if ($userId === null) {
            $this->events->record('password_reset', 'rejected', '', $ip, null, $requestId);
            return ['form' => 'That password reset link is invalid or expired.'];
        }
        $this->events->record('password_reset', 'accepted', '', $ip, $userId, $requestId);

        return [];
    }
}
