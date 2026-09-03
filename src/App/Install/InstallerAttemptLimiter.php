<?php

declare(strict_types=1);

namespace N3\App\Install;

use N3\Core\Session\SessionStore;

final readonly class InstallerAttemptLimiter
{
    public function __construct(
        private SessionStore $session,
        private int $limit = 5,
        private int $windowSeconds = 900,
    ) {
    }

    public function allows(string $bucket, ?int $now = null): bool
    {
        $now ??= time();
        $record = $this->session->get('_install_limit_' . $bucket);
        if (!is_array($record) || !isset($record['started'], $record['count'])
            || !is_int($record['started']) || !is_int($record['count'])
            || $record['started'] <= $now - $this->windowSeconds) {
            $this->session->put('_install_limit_' . $bucket, ['started' => $now, 'count' => 1]);
            return true;
        }
        if ($record['count'] >= $this->limit) {
            return false;
        }
        $record['count']++;
        $this->session->put('_install_limit_' . $bucket, $record);

        return true;
    }

    public function clear(string $bucket): void
    {
        $this->session->remove('_install_limit_' . $bucket);
    }
}
