<?php

declare(strict_types=1);

namespace N3\App\Identity;

use N3\Core\Database\TransactionManager;
use RuntimeException;

final readonly class AdminBootstrapService
{
    public function __construct(
        private IdentityValidator $validator,
        private UserRepository $users,
        private TransactionManager $transactions,
    ) {
    }

    public function create(string $name, string $email, string $password): int
    {
        $errors = $this->validator->registrationErrors($name, $email, $password, $password);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', array_values($errors)));
        }
        $normalized = $this->validator->normalizeEmail($email);

        return $this->transactions->run(function () use ($name, $email, $normalized, $password): int {
            if ($this->users->adminExists()) {
                throw new RuntimeException('An administrator already exists; bootstrap creation is disabled.');
            }
            if ($this->users->normalizedEmailExists($normalized)) {
                throw new RuntimeException('An account with that email address already exists.');
            }

            return $this->users->createAdmin(trim($name), trim($email), $normalized, password_hash($password, PASSWORD_DEFAULT));
        });
    }
}
