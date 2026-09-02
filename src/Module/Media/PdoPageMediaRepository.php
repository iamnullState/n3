<?php

declare(strict_types=1);

namespace N3\Module\Media;

use N3\App\Content\PageMediaAttachment;
use N3\App\Content\PageMediaOption;
use N3\Core\Database\TransactionManager;
use PDO;

final readonly class PdoPageMediaRepository implements PageMediaRepository
{
    public function __construct(private PDO $connection, private TransactionManager $transactions)
    {
    }

    public function options(int $pageId): array
    {
        if ($pageId < 1) {
            return [];
        }
        $statement = $this->connection->prepare(sprintf(
            'SELECT m.public_id, m.label, m.width, m.height FROM `%s` m '
            . 'LEFT JOIN `%s` a ON a.asset_public_id = m.public_id AND a.page_id = :page_id '
            . 'ORDER BY (a.page_id IS NOT NULL) DESC, m.id DESC LIMIT 100',
            MediaSchema::assetsTable(),
            MediaSchema::attachmentsTable(),
        ));
        $statement->execute(['page_id' => $pageId]);
        $rows = $statement->fetchAll();

        return array_map(static fn (array $row): PageMediaOption => new PageMediaOption(
            (string) $row['public_id'], (string) $row['label'], (int) $row['width'], (int) $row['height'],
        ), $rows);
    }

    public function attachment(int $pageId): ?PageMediaAttachment
    {
        if ($pageId < 1) {
            return null;
        }
        $statement = $this->connection->prepare(sprintf(
            'SELECT a.asset_public_id, a.alt_text, m.width, m.height FROM `%s` a '
            . 'INNER JOIN `%s` m ON m.public_id = a.asset_public_id WHERE a.page_id = :page_id LIMIT 1',
            MediaSchema::attachmentsTable(),
            MediaSchema::assetsTable(),
        ));
        $statement->execute(['page_id' => $pageId]);
        $row = $statement->fetch();

        return is_array($row) ? new PageMediaAttachment(
            (string) $row['asset_public_id'], (string) $row['alt_text'], (int) $row['width'], (int) $row['height'],
        ) : null;
    }

    public function updateDraft(
        int $pageId,
        ?string $publicId,
        string $altText,
        int $actorId,
        int $expectedVersion,
        string $requestId,
    ): string {
        return $this->transactions->run(function () use ($pageId, $publicId, $altText, $actorId, $expectedVersion, $requestId): string {
            $pageState = $this->connection->prepare('SELECT status, lock_version FROM pages WHERE id = :page_id LIMIT 1 FOR UPDATE');
            $pageState->execute(['page_id' => $pageId]);
            $state = $pageState->fetch();
            if (!is_array($state) || $state['status'] !== 'draft' || (int) $state['lock_version'] !== $expectedVersion) {
                return 'conflict';
            }
            $current = $this->attachment($pageId);
            if (($publicId === null && $current === null)
                || ($current !== null && $current->publicId === $publicId && $current->altText === $altText)) {
                return 'unchanged';
            }
            if ($publicId !== null) {
                $asset = $this->connection->prepare(sprintf(
                    'SELECT 1 FROM `%s` WHERE public_id = :public_id LIMIT 1',
                    MediaSchema::assetsTable(),
                ));
                $asset->execute(['public_id' => $publicId]);
                if ($asset->fetchColumn() === false) {
                    return 'missing_asset';
                }
            }

            $page = $this->connection->prepare(
                "UPDATE pages SET updated_by = :actor_id, lock_version = lock_version + 1 "
                . "WHERE id = :page_id AND status = 'draft' AND lock_version = :expected_version",
            );
            $page->execute([
                'actor_id' => $actorId,
                'page_id' => $pageId,
                'expected_version' => $expectedVersion,
            ]);
            if ($page->rowCount() !== 1) {
                return 'conflict';
            }

            if ($publicId === null) {
                $statement = $this->connection->prepare(sprintf(
                    'DELETE FROM `%s` WHERE page_id = :page_id',
                    MediaSchema::attachmentsTable(),
                ));
                $statement->execute(['page_id' => $pageId]);
                $event = 'media_detached';
            } else {
                $statement = $this->connection->prepare(sprintf(
                    'INSERT INTO `%s` (page_id, asset_public_id, alt_text, created_at, updated_at) '
                    . 'VALUES (:page_id, :asset_public_id, :alt_text, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) '
                    . 'ON DUPLICATE KEY UPDATE asset_public_id = VALUES(asset_public_id), '
                    . 'alt_text = VALUES(alt_text), updated_at = UTC_TIMESTAMP(6)',
                    MediaSchema::attachmentsTable(),
                ));
                $statement->execute(['page_id' => $pageId, 'asset_public_id' => $publicId, 'alt_text' => $altText]);
                $event = 'media_attached';
            }
            $audit = $this->connection->prepare(
                'INSERT INTO content_events (page_id, actor_user_id, event_type, from_status, to_status, request_id) '
                . "VALUES (:page_id, :actor_id, :event_type, 'draft', 'draft', :request_id)",
            );
            $audit->execute([
                'page_id' => $pageId,
                'actor_id' => $actorId,
                'event_type' => $event,
                'request_id' => $requestId,
            ]);

            return $publicId === null ? 'detached' : 'attached';
        });
    }

    public function isPubliclyAttached(string $publicId): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            return false;
        }
        $statement = $this->connection->prepare(sprintf(
            'SELECT 1 FROM `%s` a INNER JOIN pages p ON p.id = a.page_id '
            . "WHERE a.asset_public_id = :public_id AND p.status = 'published' LIMIT 1",
            MediaSchema::attachmentsTable(),
        ));
        $statement->execute(['public_id' => $publicId]);

        return $statement->fetchColumn() !== false;
    }
}
