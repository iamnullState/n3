<?php

declare(strict_types=1);

namespace N3\Module\Media;

use DateTimeImmutable;
use DateTimeZone;
use N3\Core\Database\TransactionManager;
use PDO;

final readonly class PdoMediaRepository implements MediaRepository
{
    public function __construct(
        private PDO $connection,
        private TransactionManager $transactions,
        private string $securityHashKey,
    ) {
    }

    public function list(int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Media list limits must be between 1 and 100.');
        }
        $statement = $this->connection->query(sprintf(
            'SELECT public_id, label, width, height, byte_size, sha256, created_at '
            . 'FROM `%s` ORDER BY id DESC LIMIT %d',
            MediaSchema::assetsTable(),
            $limit,
        ));

        return array_map(fn (array $row): MediaAsset => $this->map($row), $statement->fetchAll());
    }

    public function find(string $publicId): ?MediaAsset
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            return null;
        }
        $statement = $this->connection->prepare(sprintf(
            'SELECT public_id, label, width, height, byte_size, sha256, created_at '
            . 'FROM `%s` WHERE public_id = :public_id LIMIT 1',
            MediaSchema::assetsTable(),
        ));
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->map($row) : null;
    }

    public function create(MediaAsset $asset): void
    {
        $this->transactions->run(function () use ($asset): void {
            $statement = $this->connection->prepare(sprintf(
                'INSERT INTO `%s` (public_id, label, width, height, byte_size, sha256, created_at) '
                . 'VALUES (:public_id, :label, :width, :height, :byte_size, :sha256, :created_at)',
                MediaSchema::assetsTable(),
            ));
            $statement->execute([
                'public_id' => $asset->publicId,
                'label' => $asset->label,
                'width' => $asset->width,
                'height' => $asset->height,
                'byte_size' => $asset->byteSize,
                'sha256' => $asset->sha256,
                'created_at' => $asset->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            ]);
            $this->insertEvent('upload_succeeded', $asset->publicId);
        });
    }

    public function allowUpload(string $subject, int $now, int $limit): bool
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Media upload limits must be between 1 and 100.');
        }
        $window = intdiv($now, 3600) * 3600;
        $hash = hash_hmac('sha256', $subject, $this->securityHashKey);
        $statement = $this->connection->prepare(sprintf(
            'INSERT INTO `%s` (subject_hash, window_start, attempts) VALUES (:subject, :window, 1) '
            . 'ON DUPLICATE KEY UPDATE attempts = attempts + 1',
            MediaSchema::limitsTable(),
        ));
        $statement->execute(['subject' => $hash, 'window' => $window]);
        $read = $this->connection->prepare(sprintf(
            'SELECT attempts FROM `%s` WHERE subject_hash = :subject AND window_start = :window',
            MediaSchema::limitsTable(),
        ));
        $read->execute(['subject' => $hash, 'window' => $window]);
        $this->connection->prepare(sprintf('DELETE FROM `%s` WHERE window_start < :cutoff', MediaSchema::limitsTable()))
            ->execute(['cutoff' => $window - 172_800]);

        return (int) $read->fetchColumn() <= $limit;
    }

    public function recordEvent(string $eventKey, ?string $assetPublicId = null): void
    {
        $this->insertEvent($eventKey, $assetPublicId);
    }

    private function insertEvent(string $eventKey, ?string $assetPublicId): void
    {
        if (!in_array($eventKey, ['upload_rejected', 'upload_rate_limited', 'upload_succeeded'], true)) {
            throw new \InvalidArgumentException('Media events must use the controlled event vocabulary.');
        }
        if ($assetPublicId !== null && !preg_match('/^[a-f0-9]{32}$/D', $assetPublicId)) {
            throw new \InvalidArgumentException('Media event asset identifiers are invalid.');
        }
        $statement = $this->connection->prepare(sprintf(
            'INSERT INTO `%s` (event_key, asset_public_id, occurred_at) VALUES (:event_key, :asset_public_id, UTC_TIMESTAMP(6))',
            MediaSchema::eventsTable(),
        ));
        $statement->execute(['event_key' => $eventKey, 'asset_public_id' => $assetPublicId]);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): MediaAsset
    {
        return new MediaAsset(
            (string) $row['public_id'],
            (string) $row['label'],
            (int) $row['width'],
            (int) $row['height'],
            (int) $row['byte_size'],
            (string) $row['sha256'],
            new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
        );
    }
}
