<?php

declare(strict_types=1);

namespace N3\App\Identity;

use N3\Core\Database\TransactionManager;

final readonly class RegistrationService
{
    public function __construct(
        private IdentityConfig $config,
        private IdentityValidator $validator,
        private UserRepository $users,
        private VerificationTokenRepository $tokens,
        private VerificationNotifier $notifier,
        private RateLimiter $limiter,
        private SecurityEventRecorder $events,
        private TransactionManager $transactions,
    ) {
    }

    public function register(
        string $displayName,
        string $email,
        string $password,
        string $confirmation,
        string $ip,
        string $requestId,
    ): RegistrationOutcome {
        $normalizedEmail = $this->validator->normalizeEmail($email);
        $errors = $this->validator->registrationErrors($displayName, $email, $password, $confirmation);

        if ($errors !== []) {
            return new RegistrationOutcome($errors);
        }

        $allowed = $this->limiter->allow('register_ip', 'ip:' . $ip, 5, 3600)
            && $this->limiter->allow('register_email', 'email:' . $normalizedEmail, 3, 3600);

        if (!$allowed) {
            $this->events->record('registration', 'rate_limited', $normalizedEmail, $ip, null, $requestId);
            return new RegistrationOutcome(rateLimited: true);
        }

        $existing = $this->users->findByNormalizedEmail($normalizedEmail);

        if ($existing !== null) {
            $this->events->record('registration', 'accepted_existing', $normalizedEmail, $ip, $existing->id, $requestId);
            return new RegistrationOutcome();
        }

        $token = self::randomToken();
        $userId = $this->transactions->run(function () use (
            $displayName,
            $email,
            $normalizedEmail,
            $password,
            $token,
        ): int {
            $userId = $this->users->createPending(
                trim($displayName),
                trim($email),
                $normalizedEmail,
                password_hash($password, PASSWORD_DEFAULT),
            );
            $this->tokens->issue($userId, hash('sha256', $token), time() + $this->config->verificationTtl);

            return $userId;
        });

        $url = $this->config->appUrl . '/verify-email?token=' . rawurlencode($token);
        $this->notifier->sendVerification(trim($email), trim($displayName), $url);
        $this->events->record('registration', 'created', $normalizedEmail, $ip, $userId, $requestId);

        return new RegistrationOutcome();
    }

    public function resend(string $email, string $ip, string $requestId): bool
    {
        $normalizedEmail = $this->validator->normalizeEmail($email);
        $allowed = $this->limiter->allow('verify_resend_ip', 'ip:' . $ip, 10, 3600)
            && $this->limiter->allow('verify_resend_email', 'email:' . $normalizedEmail, 5, 3600);

        if (!$allowed) {
            $this->events->record('verification_resend', 'rate_limited', $normalizedEmail, $ip, null, $requestId);
            return false;
        }

        $user = $this->users->findByNormalizedEmail($normalizedEmail);

        if ($user !== null && !$user->emailVerified && $user->status === 'pending_verification') {
            $token = self::randomToken();
            $this->tokens->issue($user->id, hash('sha256', $token), time() + $this->config->verificationTtl);
            $this->notifier->sendVerification(
                $user->email,
                $user->displayName,
                $this->config->appUrl . '/verify-email?token=' . rawurlencode($token),
            );
            $this->events->record('verification_resend', 'issued', $normalizedEmail, $ip, $user->id, $requestId);
        } else {
            $this->events->record('verification_resend', 'accepted_unknown', $normalizedEmail, $ip, $user?->id, $requestId);
        }

        return true;
    }

    public function verify(string $tokenHash, string $ip, string $requestId): bool
    {
        if (!$this->limiter->allow('verify_token_ip', 'ip:' . $ip, 20, 3600)) {
            $this->events->record('email_verification', 'rate_limited', '', $ip, null, $requestId);
            return false;
        }
        $userId = $this->transactions->run(function () use ($tokenHash): ?int {
            $userId = $this->tokens->consume($tokenHash, time());

            if ($userId !== null) {
                $this->users->markEmailVerified($userId);
            }

            return $userId;
        });

        $this->events->record('email_verification', $userId === null ? 'rejected' : 'verified', '', $ip, $userId, $requestId);

        return $userId !== null;
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
