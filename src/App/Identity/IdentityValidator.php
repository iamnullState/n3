<?php

declare(strict_types=1);

namespace N3\App\Identity;

final class IdentityValidator
{
    public function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /** @return array<string, string> */
    public function registrationErrors(
        string $displayName,
        string $email,
        string $password,
        string $confirmation,
    ): array {
        $errors = [];
        $displayName = trim($displayName);
        $email = $this->normalizeEmail($email);

        if (mb_strlen($displayName, 'UTF-8') < 2 || mb_strlen($displayName, 'UTF-8') > 100) {
            $errors['display_name'] = 'Display name must be between 2 and 100 characters.';
        }

        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        $passwordLength = mb_strlen($password, 'UTF-8');

        if ($passwordLength < 12 || $passwordLength > 1024 || str_contains($password, "\0")) {
            $errors['password'] = 'Password must be between 12 and 1024 characters.';
        }

        if (!hash_equals($password, $confirmation)) {
            $errors['password_confirmation'] = 'Password confirmation does not match.';
        }

        return $errors;
    }

    /** @return array<string, string> */
    public function passwordErrors(string $password, string $confirmation): array
    {
        $errors = [];
        $length = mb_strlen($password, 'UTF-8');
        if ($length < 12 || $length > 1024 || str_contains($password, "\0")) {
            $errors['password'] = 'Password must be between 12 and 1024 characters.';
        }
        if (!hash_equals($password, $confirmation)) {
            $errors['password_confirmation'] = 'Password confirmation does not match.';
        }

        return $errors;
    }
}
