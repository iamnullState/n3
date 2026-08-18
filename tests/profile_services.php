<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\ProfileRepository;
use N3\Service\AccessService;
use N3\Service\ProfileService;

function verifyProfile(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

function pageIds(array $pages): array
{
    return array_map('intval', array_column($pages, 'id'));
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec(<<<'SQL'
    CREATE TABLE users (
        id INTEGER PRIMARY KEY, username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        display_name TEXT NOT NULL DEFAULT '', biography TEXT NOT NULL DEFAULT '',
        profile_slug TEXT NOT NULL UNIQUE COLLATE NOCASE,
        profile_visibility TEXT NOT NULL DEFAULT 'private', avatar_reference TEXT
    );
    CREATE TABLE spaces (id INTEGER PRIMARY KEY, owner_id INTEGER, name TEXT NOT NULL);
    CREATE TABLE pages (
        id INTEGER PRIMARY KEY, space_id INTEGER NOT NULL, parent_id INTEGER,
        author_id INTEGER, slug TEXT, title TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'page',
        is_public INTEGER NOT NULL DEFAULT 0, is_deleted INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL, first_published_at TEXT
    );
    CREATE TABLE resource_shares (
        id INTEGER PRIMARY KEY AUTOINCREMENT, resource_type TEXT NOT NULL,
        resource_id INTEGER NOT NULL, user_id INTEGER NOT NULL, role TEXT NOT NULL
    );

    INSERT INTO users VALUES
        (1, 'private-owner', 'Private Owner', 'Hidden', 'private-owner-1', 'private', NULL),
        (2, 'member', 'Member Author', 'Writes notes', 'member-2', 'members', 'avatars/member.webp'),
        (3, 'public-author', '', 'Public bio', 'public-author-3', 'public', NULL),
        (4, 'viewer', 'A Viewer', '', 'viewer-4', 'private', NULL);
    INSERT INTO spaces VALUES (1, 1, 'Private'), (2, 2, 'Member'), (3, 3, 'Public');

    INSERT INTO pages VALUES
        (21, 2, NULL, 2, 'private-own-21', 'Private own', 'page', 0, 0, '2026-01-01', '2026-01-21', NULL),
        (22, 2, NULL, 2, 'public-own-22', 'Public own', 'page', 1, 0, '2026-01-01', '2026-01-22', '2026-01-22'),
        (23, 2, NULL, 2, 'shared-private-23', 'Shared private', 'page', 0, 0, '2026-01-01', '2026-01-23', NULL),
        (24, 2, NULL, 2, 'deleted-24', 'Deleted', 'page', 1, 1, '2026-01-01', '2026-01-24', '2026-01-24'),
        (25, 2, NULL, 2, NULL, 'Folder', 'folder', 1, 0, '2026-01-01', '2026-01-25', NULL),
        (26, 2, NULL, 3, 'guest-work-26', 'Guest work', 'page', 0, 0, '2026-01-01', '2026-01-26', NULL),
        (27, 1, NULL, 2, 'collab-private-27', 'Collab private', 'page', 0, 0, '2026-01-01', '2026-01-27', NULL),
        (28, 1, NULL, 2, 'collab-public-28', 'Collab public', 'page', 1, 0, '2026-01-01', '2026-01-28', '2026-01-28'),
        (31, 3, NULL, 3, 'public-31', 'Public article', 'page', 1, 0, '2026-01-01', '2026-01-31', '2026-01-31'),
        (32, 3, NULL, 3, 'private-32', 'Private article', 'page', 0, 0, '2026-01-01', '2026-02-01', NULL);
    INSERT INTO resource_shares (resource_type, resource_id, user_id, role) VALUES
        ('page', 23, 4, 'viewer'),
        ('page', 27, 2, 'editor'),
        ('page', 28, 2, 'viewer');
SQL);

$profiles = new ProfileRepository($database);

verifyProfile((new ProfileService($profiles))->viewBySlug('private-owner-1') === null, 'anonymous viewers cannot discover private profiles');
verifyProfile((new ProfileService($profiles, new AccessService($database, 4), 4))->viewBySlug('private-owner-1') === null, 'signed-in viewers cannot discover another private profile');
verifyProfile((new ProfileService($profiles))->viewBySlug('member-2') === null, 'anonymous viewers cannot discover members-only profiles');

$self = (new ProfileService($profiles, new AccessService($database, 2), 2))->viewBySlug('member-2');
verifyProfile($self !== null && $self['is_self'] && $self['audience'] === 'self', 'owners can view their own profile regardless of visibility');
verifyProfile(pageIds($self['pages']['owned']) === [26, 23, 22, 21], 'self profile lists active page rows in owned spaces');
verifyProfile(pageIds($self['pages']['shared']) === [28, 27], 'self profile lists accessible page rows outside owned spaces');
verifyProfile(pageIds($self['pages']['published']) === [28, 22], 'self profile lists active public pages by authorship');
verifyProfile(array_column($self['pages']['published'], 'url') === ['/page/collab-public-28', '/page/public-own-22'], 'self profile page links stay on authenticated editor routes');
verifyProfile($self['counts'] === ['owned' => 4, 'shared' => 2, 'published' => 2], 'self profile counts are derived after filtering');

$viewer = (new ProfileService($profiles, new AccessService($database, 4), 4))->viewBySlug('member-2');
verifyProfile(pageIds($viewer['pages']['authored']) === [28, 23, 22], 'signed-in profile views combine public and privately accessible authored pages');
verifyProfile(array_column($viewer['pages']['authored'], 'url') === ['/p/collab-public-28', '/page/shared-private-23', '/p/public-own-22'], 'signed-in profile links use private routes only for pages the viewer can access');
verifyProfile($viewer['counts'] === ['authored' => 3], 'signed-in counts do not disclose inaccessible authored pages');
verifyProfile($viewer['has_avatar'] === true && !array_key_exists('avatar_reference', $viewer), 'profile summaries expose avatar availability without leaking its storage reference');
verifyProfile(
    !str_contains(json_encode($viewer, JSON_THROW_ON_ERROR), 'Private own')
        && !str_contains(json_encode($viewer, JSON_THROW_ON_ERROR), 'private-own-21')
        && !str_contains(json_encode($viewer, JSON_THROW_ON_ERROR), 'Deleted')
        && !str_contains(json_encode($viewer, JSON_THROW_ON_ERROR), 'Folder'),
    'signed-in repository projections omit inaccessible, deleted, and folder titles and URLs rather than redacting them after counting',
);

$anonymous = (new ProfileService($profiles))->viewBySlug('PUBLIC-AUTHOR-3');
verifyProfile($anonymous !== null && $anonymous['display_name'] === 'public-author', 'profile slug lookup is case-insensitive and display names fall back to usernames');
verifyProfile(
    array_column($anonymous['pages']['published'], 'slug') === ['public-31']
        && array_column($anonymous['pages']['published'], 'url') === ['/p/public-31']
        && !array_key_exists('id', $anonymous['pages']['published'][0])
        && $anonymous['counts'] === ['published' => 1],
    'anonymous profile views include only public URLs for active published pages and omit internal IDs',
);
verifyProfile((new ProfileService($profiles))->viewBySlug('missing') === null, 'missing profile slugs return no summary');

echo "\nn3 profile repository and service test passed.\n";
