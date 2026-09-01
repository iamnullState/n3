<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use DateTimeImmutable;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\MigrationRunner;
use N3\Core\Webhook\PdoWebhookReplayStore;
use N3\Core\Webhook\WebhookRejected;
use N3\Core\Webhook\WebhookSigner;
use N3\Core\Webhook\WebhookVerifier;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WebhookReplayTest extends TestCase
{
    private PDO $connection;
    private string $sourceKey;

    protected function setUp(): void
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql is not installed.');
        }
        foreach ([
            'N3_TEST_DB_HOST', 'N3_TEST_DB_PORT', 'N3_TEST_DB_NAME',
            'N3_TEST_DB_USER', 'N3_TEST_DB_PASSWORD',
            'N3_TEST_DB_MIGRATION_USER', 'N3_TEST_DB_MIGRATION_PASSWORD',
        ] as $variable) {
            if (getenv($variable) === false || getenv($variable) === '') {
                $this->markTestSkipped(sprintf('%s is not configured.', $variable));
            }
        }

        $database = (string) getenv('N3_TEST_DB_NAME');
        if (!str_ends_with($database, '_test')) {
            throw new RuntimeException('Integration database names must end in _test.');
        }
        $factory = new ConnectionFactory();
        $this->connection = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'), (int) getenv('N3_TEST_DB_PORT'), $database,
            (string) getenv('N3_TEST_DB_USER'), (string) getenv('N3_TEST_DB_PASSWORD'),
        ));
        $migration = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'), (int) getenv('N3_TEST_DB_PORT'), $database,
            (string) getenv('N3_TEST_DB_MIGRATION_USER'), (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'),
        ));
        (new MigrationRunner($migration, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->sourceKey = 'test:' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection, $this->sourceKey)) {
            $statement = $this->connection->prepare('DELETE FROM webhook_receipts WHERE source_key = :source_key');
            $statement->execute(['source_key' => $this->sourceKey]);
        }
    }

    public function testVerifiedDeliveryIsConsumedAtomicallyAndCannotReplay(): void
    {
        $store = new PdoWebhookReplayStore($this->connection);
        $verifier = new WebhookVerifier($store);
        $now = new DateTimeImmutable('2026-08-31T12:00:00Z');
        $secret = 'integration-secret-with-at-least-32-bytes';
        $delivery = 'integration_delivery_0001';
        $headers = WebhookSigner::headers('{"safe":true}', $delivery, $now->getTimestamp(), $secret);

        $verifier->verify(
            $this->sourceKey, '{"safe":true}', $delivery,
            $headers['X-N3-Webhook-Timestamp'], $headers['X-N3-Webhook-Signature'], $secret, $now,
        );

        $this->expectException(WebhookRejected::class);
        $verifier->verify(
            $this->sourceKey, '{"safe":true}', $delivery,
            $headers['X-N3-Webhook-Timestamp'], $headers['X-N3-Webhook-Signature'], $secret, $now,
        );
    }

    public function testDeliveryIdsAreScopedPerSourceAndExpiredReceiptsCanBePruned(): void
    {
        $store = new PdoWebhookReplayStore($this->connection);
        $hash = hash('sha256', 'integration_delivery_0002');
        $past = new DateTimeImmutable('2026-08-31T11:00:00Z');

        self::assertTrue($store->consume($this->sourceKey, $hash, $past));
        self::assertFalse($store->consume($this->sourceKey, $hash, $past));
        self::assertTrue($store->consume($this->sourceKey . ':other', $hash, $past));
        self::assertSame(2, $store->prune(new DateTimeImmutable('2026-08-31T12:00:00Z')));

        $cleanup = $this->connection->prepare('DELETE FROM webhook_receipts WHERE source_key = :source_key');
        $cleanup->execute(['source_key' => $this->sourceKey . ':other']);
    }
}
