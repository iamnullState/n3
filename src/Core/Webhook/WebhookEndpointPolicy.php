<?php

declare(strict_types=1);

namespace N3\Core\Webhook;

use InvalidArgumentException;

final class WebhookEndpointPolicy
{
    /** @param list<string> $allowedHosts */
    public static function assertAllowed(string $url, array $allowedHosts): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $normalized = array_map('strtolower', $allowedHosts);

        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)
            || !in_array($host, $normalized, true)) {
            throw new InvalidArgumentException('Webhook destinations must be allowlisted HTTPS hostnames on port 443.');
        }
    }
}
