<?php

declare(strict_types=1);

namespace N3\Core\Security;

use N3\Core\Session\SessionStore;

final readonly class CsrfTokenManager
{
    public function __construct(private SessionStore $session)
    {
    }

    public function token(string $intent): string
    {
        $secret = $this->session->get('_csrf_secret');

        if (!is_string($secret)) {
            $secret = bin2hex(random_bytes(32));
            $this->session->put('_csrf_secret', $secret);
        }

        return hash_hmac('sha256', $intent, $secret);
    }

    public function verify(string $intent, mixed $token): bool
    {
        return is_string($token) && hash_equals($this->token($intent), $token);
    }

    public function rotate(): void
    {
        $this->session->put('_csrf_secret', bin2hex(random_bytes(32)));
    }
}
