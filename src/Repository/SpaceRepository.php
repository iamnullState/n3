<?php
declare(strict_types=1);

namespace N3\Repository;

use PDO;

final class SpaceRepository
{
    public function __construct(private readonly PDO $database) {}

    public function all(?array $ids = null): array
    {
        if ($ids === null) return $this->database->query('SELECT * FROM spaces ORDER BY name')->fetchAll();
        if ($ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->database->prepare("SELECT * FROM spaces WHERE id IN ($placeholders) ORDER BY name");
        $statement->execute(array_map('intval', $ids));
        return $statement->fetchAll();
    }

    public function exists(int $id): bool
    {
        $statement = $this->database->prepare('SELECT 1 FROM spaces WHERE id = ?');
        $statement->execute([$id]);
        return (bool)$statement->fetchColumn();
    }

    public function count(): int
    {
        return (int)$this->database->query('SELECT COUNT(*) FROM spaces')->fetchColumn();
    }

    public function create(string $name, string $description, string $icon, string $color, ?int $ownerId = null): int
    {
        if ($ownerId === null) {
            $statement = $this->database->prepare('INSERT INTO spaces (name, description, icon, color) VALUES (?, ?, ?, ?)');
            $statement->execute([$name, $description, $icon, $color]);
        } else {
            $statement = $this->database->prepare('INSERT INTO spaces (name, description, icon, color, owner_id) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([$name, $description, $icon, $color, $ownerId]);
        }
        return (int)$this->database->lastInsertId();
    }

    public function update(int $id, string $name, string $description, string $color): void
    {
        $statement = $this->database->prepare('UPDATE spaces SET name = ?, description = ?, color = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $statement->execute([$name, $description, $color, $id]);
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare('DELETE FROM spaces WHERE id = ?');
        $statement->execute([$id]);
    }
}
