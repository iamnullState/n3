<?php

declare(strict_types=1);

namespace N3\App\Identity;

use RuntimeException;

final readonly class LocalOutboxNotifier implements VerificationNotifier
{
    public function __construct(private string $path)
    {
    }

    public function sendVerification(string $email, string $displayName, string $url): void
    {
        $this->write([
            'type' => 'email_verification',
            'to' => $email,
            'display_name' => $displayName,
            'verification_url' => $url,
            'created_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function sendPasswordReset(string $email, string $displayName, string $url): void
    {
        $this->write([
            'type' => 'password_reset',
            'to' => $email,
            'display_name' => $displayName,
            'reset_url' => $url,
            'created_at' => gmdate(DATE_ATOM),
        ]);
    }

    /** @param array<string, string> $message */
    private function write(array $message): void
    {
        if (!is_dir($this->path) && !mkdir($this->path, 0700, true) && !is_dir($this->path)) {
            throw new RuntimeException('Unable to create the private identity outbox.');
        }

        chmod($this->path, 0700);
        $file = $this->path . '/' . bin2hex(random_bytes(16)) . '.json';
        $payload = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($file, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the identity outbox message.');
        }

        chmod($file, 0600);
    }

    public function prune(int $olderThanEpoch): int
    {
        $removed = 0;

        foreach (glob($this->path . '/*.json') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $olderThanEpoch && unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }
}
