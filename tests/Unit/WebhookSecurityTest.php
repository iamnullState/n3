<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use DateTimeImmutable;
use N3\Core\Webhook\WebhookDeliveryPolicy;
use N3\Core\Webhook\WebhookEndpointPolicy;
use N3\Core\Webhook\WebhookRejected;
use N3\Core\Webhook\WebhookReplayStore;
use N3\Core\Webhook\WebhookSigner;
use N3\Core\Webhook\WebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookSecurityTest extends TestCase
{
    private const SECRET = 'test-secret-with-at-least-thirty-two-bytes';
    private const DELIVERY = 'delivery_0000000001';

    public function testExactBodySignatureIsVerifiedAndReplayIsRejected(): void
    {
        $store = new MemoryWebhookReplays();
        $verifier = new WebhookVerifier($store);
        $now = new DateTimeImmutable('2026-08-31T12:00:00Z');
        $headers = WebhookSigner::headers('{"event":"page.published"}', self::DELIVERY, $now->getTimestamp(), self::SECRET);

        $verifier->verify(
            'service:test',
            '{"event":"page.published"}',
            $headers['X-N3-Webhook-ID'],
            $headers['X-N3-Webhook-Timestamp'],
            $headers['X-N3-Webhook-Signature'],
            self::SECRET,
            $now,
        );
        self::assertCount(1, $store->consumed);

        $this->expectException(WebhookRejected::class);
        $this->expectExceptionMessage('authentication failed');
        $verifier->verify(
            'service:test',
            '{"event":"page.published"}',
            $headers['X-N3-Webhook-ID'],
            $headers['X-N3-Webhook-Timestamp'],
            $headers['X-N3-Webhook-Signature'],
            self::SECRET,
            $now,
        );
    }

    public function testTamperedBodyFailsBeforeConsumingReplayState(): void
    {
        $store = new MemoryWebhookReplays();
        $now = new DateTimeImmutable('2026-08-31T12:00:00Z');
        $headers = WebhookSigner::headers('original', self::DELIVERY, $now->getTimestamp(), self::SECRET);

        try {
            (new WebhookVerifier($store))->verify(
                'service:test',
                'tampered',
                self::DELIVERY,
                $headers['X-N3-Webhook-Timestamp'],
                $headers['X-N3-Webhook-Signature'],
                self::SECRET,
                $now,
            );
            self::fail('A tampered body was accepted.');
        } catch (WebhookRejected $exception) {
            self::assertSame('invalid_signature', $exception->errorCode);
            self::assertSame([], $store->consumed);
        }
    }

    public function testStaleAndFutureTimestampsAreRejected(): void
    {
        $now = new DateTimeImmutable('2026-08-31T12:00:00Z');
        foreach ([$now->modify('-301 seconds'), $now->modify('+301 seconds')] as $outsideWindow) {
            $headers = WebhookSigner::headers(
                'body',
                self::DELIVERY,
                $outsideWindow->getTimestamp(),
                self::SECRET,
            );

            try {
                (new WebhookVerifier(new MemoryWebhookReplays()))->verify(
                    'service:test', 'body', self::DELIVERY, (string) $outsideWindow->getTimestamp(),
                    $headers['X-N3-Webhook-Signature'], self::SECRET, $now,
                );
                self::fail('A timestamp outside the accepted window was accepted.');
            } catch (WebhookRejected $exception) {
                self::assertSame('stale_timestamp', $exception->errorCode);
            }
        }
    }

    #[DataProvider('deliveryStatusCases')]
    public function testDeliveryStatusPolicy(int $status, bool $successful, bool $retryable): void
    {
        self::assertSame($successful, WebhookDeliveryPolicy::isSuccessful($status));
        self::assertSame($retryable, WebhookDeliveryPolicy::isRetryable($status));
    }

    /** @return iterable<string, array{int, bool, bool}> */
    public static function deliveryStatusCases(): iterable
    {
        yield 'success' => [204, true, false];
        yield 'request timeout' => [408, false, true];
        yield 'too early' => [425, false, true];
        yield 'rate limited' => [429, false, true];
        yield 'server error' => [503, false, true];
        yield 'permanent client failure' => [422, false, false];
    }

    #[DataProvider('endpointCases')]
    public function testOutboundDestinationsRequireExactHttpsAllowlisting(string $url, bool $allowed): void
    {
        if (!$allowed) {
            $this->expectException(\InvalidArgumentException::class);
        }

        WebhookEndpointPolicy::assertAllowed($url, ['hooks.example.test']);
        if ($allowed) {
            self::assertTrue(true);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function endpointCases(): iterable
    {
        yield 'allowed' => ['https://hooks.example.test/events', true];
        yield 'http' => ['http://hooks.example.test/events', false];
        yield 'credentials' => ['https://user:pass@hooks.example.test/events', false];
        yield 'unlisted' => ['https://other.example.test/events', false];
        yield 'ip literal' => ['https://127.0.0.1/events', false];
        yield 'nonstandard port' => ['https://hooks.example.test:8443/events', false];
    }
}

final class MemoryWebhookReplays implements WebhookReplayStore
{
    /** @var array<string, true> */
    public array $consumed = [];

    public function consume(string $sourceKey, string $deliveryHash, DateTimeImmutable $expiresAt): bool
    {
        $key = $sourceKey . ':' . $deliveryHash;
        if (isset($this->consumed[$key])) {
            return false;
        }
        $this->consumed[$key] = true;
        return true;
    }

    public function prune(DateTimeImmutable $before): int
    {
        return 0;
    }
}
