<?php

declare(strict_types=1);

namespace N3\Module\McpServer;

use InvalidArgumentException;

final class McpRateLimiter
{
    private ?int $windowStartedAt = null;
    private int $attempts = 0;

    public function __construct(
        private readonly int $maximumAttempts = 60,
        private readonly int $windowSeconds = 60,
    ) {
        if ($maximumAttempts < 1 || $windowSeconds < 1) {
            throw new InvalidArgumentException('MCP rate-limit bounds must be positive.');
        }
    }

    public function attempt(int $now): bool
    {
        if ($this->windowStartedAt === null || $now >= $this->windowStartedAt + $this->windowSeconds) {
            $this->windowStartedAt = $now;
            $this->attempts = 0;
        }

        ++$this->attempts;

        return $this->attempts <= $this->maximumAttempts;
    }
}
