<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\TrashRepository;

function verifyTrash(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(<<<'SQL'
    CREATE TABLE pages (id INTEGER PRIMARY KEY, parent_id INTEGER, title TEXT, is_deleted INTEGER, updated_at TEXT);
    INSERT INTO pages VALUES
        (1, NULL, 'Deleted tree', 1, '2026-01-02 00:00:00'),
        (2, 1, 'Deleted child', 1, '2026-01-03 00:00:00'),
        (3, NULL, 'Active parent', 0, '2026-01-04 00:00:00'),
        (4, 3, 'Separately deleted child', 1, '2026-01-05 00:00:00'),
        (5, NULL, 'Active page', 0, '2026-01-06 00:00:00');
SQL);

$trash = new TrashRepository($database);
$roots = $trash->roots();
verifyTrash(array_column($roots, 'id') === [4, 1], 'trash lists newest deleted subtree roots first');
verifyTrash(!in_array(2, array_column($roots, 'id'), true), 'trash suppresses descendants whose parent is already deleted');
verifyTrash(array_keys($roots[0]) === ['id', 'title', 'updated_at'], 'trash listing preserves the existing response shape');

echo "\nn3 trash repository test passed.\n";
