<?php
declare(strict_types=1);

namespace N3\Repository;

use PDO;

final class PublicPageRepository
{
    public function __construct(private readonly PDO $database) {}

    public function published(): array
    {
        return $this->database->query(
            "SELECT slug, title, content, updated_at FROM pages WHERE kind = 'page' AND is_public = 1 AND is_deleted = 0 ORDER BY updated_at DESC"
        )->fetchAll();
    }

    public function search(string $query = '', string $tag = ''): array
    {
        $sql = "SELECT pages.id, pages.slug, pages.title, pages.content, pages.updated_at, GROUP_CONCAT(tags.name, '||') AS tags FROM pages LEFT JOIN page_tags ON page_tags.page_id = pages.id LEFT JOIN tags ON tags.id = page_tags.tag_id WHERE pages.kind = 'page' AND pages.is_public = 1 AND pages.is_deleted = 0";
        $params = [];
        if ($query !== '') {
            $sql .= ' AND (pages.title LIKE :query OR pages.content LIKE :query)';
            $params['query'] = '%' . $query . '%';
        }
        if ($tag !== '') {
            $sql .= ' AND EXISTS (SELECT 1 FROM page_tags filter_links JOIN tags filter_tags ON filter_tags.id = filter_links.tag_id WHERE filter_links.page_id = pages.id AND filter_tags.name = :tag COLLATE NOCASE)';
            $params['tag'] = $tag;
        }
        $sql .= ' GROUP BY pages.id ORDER BY pages.updated_at DESC';
        $statement = $this->database->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function tags(): array
    {
        return $this->database->query(
            "SELECT tags.name, COUNT(DISTINCT pages.id) AS page_count FROM tags JOIN page_tags ON page_tags.tag_id = tags.id JOIN pages ON pages.id = page_tags.page_id WHERE pages.kind = 'page' AND pages.is_public = 1 AND pages.is_deleted = 0 GROUP BY tags.id ORDER BY tags.name COLLATE NOCASE"
        )->fetchAll();
    }

    public function findBySlug(string $slug): ?array
    {
        $statement = $this->database->prepare(
            "SELECT id, slug, title, content, author_id, feature_image, feature_image_opacity, created_at, first_published_at, updated_at FROM pages WHERE slug = ? AND kind = 'page' AND is_public = 1 AND is_deleted = 0"
        );
        $statement->execute([$slug]);
        return $statement->fetch() ?: null;
    }

    public function slugForId(int $id): ?string
    {
        $statement = $this->database->prepare(
            "SELECT slug FROM pages WHERE id = ? AND kind = 'page' AND is_public = 1 AND is_deleted = 0"
        );
        $statement->execute([$id]);
        $slug = $statement->fetchColumn();
        return $slug === false ? null : (string)$slug;
    }

    public function publishedSlugForEditorTarget(string $target): ?string
    {
        if (ctype_digit($target)) return $this->slugForId((int)$target);
        $page = $this->findBySlug($target);
        return $page === null ? null : (string)$page['slug'];
    }

    public function tagsForPage(int $pageId): array
    {
        $statement = $this->database->prepare(
            'SELECT tags.name FROM tags JOIN page_tags ON page_tags.tag_id = tags.id WHERE page_tags.page_id = ? ORDER BY tags.name COLLATE NOCASE'
        );
        $statement->execute([$pageId]);
        return array_column($statement->fetchAll(), 'name');
    }

    public function directory(): array
    {
        $nodes = $this->database->query(<<<'SQL'
            WITH RECURSIVE public_tree(id) AS (
                SELECT id
                FROM pages
                WHERE kind = 'page' AND is_public = 1 AND is_deleted = 0
                UNION
                SELECT parent.id
                FROM public_tree
                JOIN pages child ON child.id = public_tree.id
                JOIN pages parent ON parent.id = child.parent_id
                WHERE parent.is_deleted = 0
            )
            SELECT pages.id, pages.space_id, pages.parent_id,
                   CASE WHEN pages.kind = 'page' AND pages.is_public = 0 THEN NULL ELSE pages.title END AS title,
                   CASE WHEN pages.kind = 'page' AND pages.is_public = 1 THEN pages.slug ELSE NULL END AS slug,
                   pages.kind, pages.position, pages.is_public
            FROM pages
            JOIN public_tree ON public_tree.id = pages.id
            ORDER BY pages.position, pages.title COLLATE NOCASE
        SQL)->fetchAll();
        $spaceIds = array_values(array_unique(array_map('intval', array_column($nodes, 'space_id'))));
        $spaces = [];
        if ($spaceIds !== []) {
            $statement = $this->database->prepare('SELECT id, name, color FROM spaces WHERE id IN (' . implode(',', array_fill(0, count($spaceIds), '?')) . ') ORDER BY name COLLATE NOCASE');
            $statement->execute($spaceIds);
            $spaces = $statement->fetchAll();
        }
        return [
            'spaces' => $spaces,
            'nodes' => $nodes,
        ];
    }

    public function referencesForPage(int $pageId): array
    {
        $statement = $this->database->prepare('SELECT label, url FROM page_references WHERE page_id = ? ORDER BY position, id');
        $statement->execute([$pageId]);
        $references = [];
        foreach ($statement->fetchAll() as $reference) {
            if (preg_match('#^/page/([a-z0-9-]+)$#iD', (string)$reference['url'], $match)) {
                $slug = $this->publishedSlugForEditorTarget($match[1]);
                if ($slug === null) continue;
                $reference['url'] = '/p/' . $slug;
            }
            $references[] = $reference;
        }
        return $references;
    }

    public function relatedForPage(int $pageId, int $limit = 6): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT pages.id, pages.title, pages.slug, COUNT(*) AS shared_tags
            FROM page_tags source_tags
            JOIN page_tags related_tags ON related_tags.tag_id = source_tags.tag_id AND related_tags.page_id != source_tags.page_id
            JOIN pages ON pages.id = related_tags.page_id
            WHERE source_tags.page_id = ? AND pages.kind = 'page' AND pages.is_public = 1 AND pages.is_deleted = 0
            GROUP BY pages.id
            ORDER BY shared_tags DESC, pages.updated_at DESC
            LIMIT ?
        SQL);
        $statement->execute([$pageId, $limit]);
        return $statement->fetchAll();
    }
}
