<?php

declare(strict_types=1);

namespace N3\App\Site;

use N3\Core\Database\TransactionManager;
use PDO;
use RuntimeException;

final readonly class PdoSiteRepository implements SiteRepository
{
    public function __construct(private PDO $connection, private TransactionManager $transactions)
    {
    }

    public function identity(): ?SiteIdentity
    {
        $row = $this->connection->query(
            'SELECT site_name, tagline, contact_email, primary_color, logo_path, lock_version '
            . 'FROM site_settings WHERE id = 1 LIMIT 1',
        )->fetch();

        return is_array($row) ? new SiteIdentity(
            (string) $row['site_name'],
            (string) $row['tagline'],
            (string) $row['contact_email'],
            (string) $row['primary_color'],
            (string) ($row['logo_path'] ?? ''),
            (int) $row['lock_version'],
        ) : null;
    }

    public function publicNavigation(): array
    {
        $rows = $this->connection->query(
            'SELECT n.page_id, n.label, p.slug, n.position, n.is_visible, p.status '
            . 'FROM site_navigation_items n INNER JOIN pages p ON p.id = n.page_id '
            . "WHERE n.is_visible = 1 AND p.status = 'published' ORDER BY n.position, n.id LIMIT 100",
        )->fetchAll();

        return array_map($this->mapNavigation(...), $rows);
    }

    public function administrationNavigation(): array
    {
        $rows = $this->connection->query(
            'SELECT p.id AS page_id, COALESCE(n.label, p.title) AS label, p.slug, '
            . 'COALESCE(n.position, 0) AS position, COALESCE(n.is_visible, 0) AS is_visible, p.status '
            . 'FROM pages p LEFT JOIN site_navigation_items n ON n.page_id = p.id '
            . 'ORDER BY (n.id IS NULL), n.position, p.title, p.id LIMIT 200',
        )->fetchAll();

        return array_map($this->mapNavigation(...), $rows);
    }

    public function pageIdsExist(array $pageIds): bool
    {
        $pageIds = array_values(array_unique($pageIds));
        if ($pageIds === [] || count($pageIds) > 200 || array_filter($pageIds, static fn (int $id): bool => $id < 1) !== []) {
            return false;
        }
        $placeholders = implode(', ', array_fill(0, count($pageIds), '?'));
        $statement = $this->connection->prepare('SELECT COUNT(*) FROM pages WHERE id IN (' . $placeholders . ')');
        $statement->execute($pageIds);

        return (int) $statement->fetchColumn() === count($pageIds);
    }

    public function update(
        SiteIdentity $identity,
        array $navigation,
        int $actorId,
        int $expectedVersion,
        string $requestId,
    ): bool {
        return $this->transactions->run(function () use ($identity, $navigation, $actorId, $expectedVersion, $requestId): bool {
            $lock = $this->connection->query('SELECT lock_version FROM site_settings WHERE id = 1 FOR UPDATE')->fetchColumn();
            if ($lock === false || (int) $lock !== $expectedVersion) {
                return false;
            }
            $settings = $this->connection->prepare(
                'UPDATE site_settings SET site_name = :site_name, tagline = :tagline, '
                . 'contact_email = :contact_email, primary_color = :primary_color, logo_path = :logo_path, '
                . 'updated_by = :actor_id, lock_version = lock_version + 1 WHERE id = 1 AND lock_version = :version',
            );
            $settings->execute([
                'site_name' => $identity->name,
                'tagline' => $identity->tagline,
                'contact_email' => $identity->contactEmail,
                'primary_color' => $identity->primaryColor,
                'logo_path' => $identity->logoPath === '' ? null : $identity->logoPath,
                'actor_id' => $actorId,
                'version' => $expectedVersion,
            ]);
            if ($settings->rowCount() !== 1) {
                return false;
            }

            $this->connection->exec('DELETE FROM site_navigation_items');
            $insert = $this->connection->prepare(
                'INSERT INTO site_navigation_items (page_id, label, position, is_visible) '
                . 'VALUES (:page_id, :label, :position, :is_visible)',
            );
            foreach ($navigation as $item) {
                $insert->execute([
                    'page_id' => $item['page_id'],
                    'label' => $item['label'],
                    'position' => $item['position'],
                    'is_visible' => $item['visible'] ? 1 : 0,
                ]);
            }
            $this->event('site_updated', $actorId, $requestId);

            return true;
        });
    }

    public function scaffold(string $normalizedAdminEmail, string $requestId): ScaffoldOutcome
    {
        return $this->transactions->run(function () use ($normalizedAdminEmail, $requestId): ScaffoldOutcome {
            $admin = $this->connection->prepare(
                "SELECT id FROM users WHERE email_normalized = :email AND role_key = 'admin' "
                . "AND account_status = 'active' AND email_verified_at IS NOT NULL LIMIT 1 FOR UPDATE",
            );
            $admin->execute(['email' => $normalizedAdminEmail]);
            $actorId = $admin->fetchColumn();
            if ($actorId === false) {
                throw new RuntimeException('The scaffold requires the matching active verified administrator.');
            }
            $actorId = (int) $actorId;
            $createdSettings = false;
            $settings = $this->connection->prepare(
                "INSERT INTO site_settings (id, site_name, tagline, contact_email, primary_color, updated_by) "
                . "VALUES (1, 'N3 Site', 'Built with N3', :email, '#2457D6', :actor_id) "
                . 'ON DUPLICATE KEY UPDATE id = id',
            );
            $settings->execute(['email' => $normalizedAdminEmail, 'actor_id' => $actorId]);
            $createdSettings = $settings->rowCount() === 1;

            $defaults = [
                ['Home', 'home', 'Welcome to our site.', "Welcome. Use the N3 page editor to shape this homepage for your organization."],
                ['About', 'about', 'Learn more about us.', "Tell visitors who you are, what you do, and why your work matters."],
                ['Contact', 'contact', 'How to contact us.', "Contact details are managed by the site owner. A submission form is not enabled."],
                ['Privacy Policy', 'privacy-policy', 'How this site handles information.', "Replace this draft policy with reviewed language appropriate for your organization and jurisdiction."],
                ['Terms', 'terms', 'Terms for using this site.', "Replace these draft terms with reviewed language appropriate for your organization and jurisdiction."],
            ];
            $find = $this->connection->prepare('SELECT id FROM pages WHERE slug = :slug LIMIT 1 FOR UPDATE');
            $create = $this->connection->prepare(
                "INSERT INTO pages (title, slug, excerpt, body, status, published_at, author_id, updated_by) "
                . "VALUES (:title, :slug, :excerpt, :body, 'published', UTC_TIMESTAMP(6), :author_id, :updated_by)",
            );
            $contentEvent = $this->connection->prepare(
                'INSERT INTO content_events (page_id, actor_user_id, event_type, from_status, to_status, request_id) '
                . "VALUES (:page_id, :actor_id, :event_type, :from_status, :to_status, :request_id)",
            );
            $navigation = $this->connection->prepare(
                'INSERT INTO site_navigation_items (page_id, label, position, is_visible) '
                . 'VALUES (:page_id, :label, :position, 1)',
            );
            $navigationExists = $this->connection->prepare('SELECT 1 FROM site_navigation_items WHERE page_id = :page_id LIMIT 1');
            $positions = array_map('intval', $this->connection->query('SELECT position FROM site_navigation_items')->fetchAll(PDO::FETCH_COLUMN));
            $maximumPosition = $positions === [] ? 0 : max($positions);
            $created = 0;
            $existing = 0;
            foreach ($defaults as $index => [$title, $slug, $excerpt, $body]) {
                $find->execute(['slug' => $slug]);
                $pageId = $find->fetchColumn();
                if ($pageId === false) {
                    $create->execute(compact('title', 'slug', 'excerpt', 'body') + [
                        'author_id' => $actorId,
                        'updated_by' => $actorId,
                    ]);
                    $pageId = (int) $this->connection->lastInsertId();
                    foreach ([['created', null, 'draft'], ['published', 'draft', 'published']] as [$event, $from, $to]) {
                        $contentEvent->execute([
                            'page_id' => $pageId, 'actor_id' => $actorId, 'event_type' => $event,
                            'from_status' => $from, 'to_status' => $to, 'request_id' => $requestId,
                        ]);
                    }
                    ++$created;
                } else {
                    $pageId = (int) $pageId;
                    ++$existing;
                }
                $navigationExists->execute(['page_id' => $pageId]);
                if ($navigationExists->fetchColumn() === false) {
                    $position = ($index + 1) * 10;
                    if (in_array($position, $positions, true)) {
                        $position = $maximumPosition + 10;
                    }
                    if ($position > 65535) {
                        throw new RuntimeException('Navigation has no remaining valid position for scaffold items.');
                    }
                    $navigation->execute(['page_id' => $pageId, 'label' => $title, 'position' => $position]);
                    $positions[] = $position;
                    $maximumPosition = max($maximumPosition, $position);
                }
            }
            $this->event('scaffold_installed', $actorId, $requestId);

            return new ScaffoldOutcome($created, $existing, $createdSettings);
        });
    }

    /** @param array<string, mixed> $row */
    private function mapNavigation(array $row): NavigationItem
    {
        return new NavigationItem(
            (int) $row['page_id'], (string) $row['label'], (string) $row['slug'],
            (int) $row['position'], (bool) $row['is_visible'], $row['status'] === 'published',
        );
    }

    private function event(string $key, int $actorId, string $requestId): void
    {
        if (!in_array($key, ['scaffold_installed', 'site_updated'], true)) {
            throw new \InvalidArgumentException('Site events must use the controlled vocabulary.');
        }
        $statement = $this->connection->prepare(
            'INSERT INTO site_events (event_key, actor_user_id, request_id) VALUES (:event_key, :actor_id, :request_id)',
        );
        $statement->execute([
            'event_key' => $key,
            'actor_id' => $actorId,
            'request_id' => preg_match('/^[a-f0-9]{16}$/D', $requestId) ? $requestId : null,
        ]);
    }
}
