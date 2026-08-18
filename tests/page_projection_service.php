<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\ProfileRepository;
use N3\Service\PageInformationService;
use N3\Service\PageProjectionService;

function verifyPageProjection(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(<<<'SQL'
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL,
        display_name TEXT NOT NULL,
        profile_slug TEXT NOT NULL,
        profile_visibility TEXT NOT NULL,
        avatar_reference TEXT
    );
    INSERT INTO users VALUES (7, 'internal-login', 'Display Author', 'stable-author-7', 'public', 'opaque-avatar.png');
SQL);

$projection = new PageProjectionService(new PageInformationService(new ProfileRepository($database)));
$raw = [
    'id' => '42',
    'space_id' => '3',
    'parent_id' => null,
    'title' => 'Projected page',
    'slug' => 'projected-page-42',
    'kind' => 'page',
    'content' => '<p>Three projected words</p>',
    'position' => '2',
    'is_favorite' => '1',
    'is_public' => '1',
    'is_deleted' => '0',
    'content_revision' => '4',
    'author_id' => '7',
    'last_editor_id' => '99',
    'first_published_at' => '2026-07-24 08:09:10',
    'created_at' => '2026-07-20 01:02:03',
    'updated_at' => '2026-07-25 04:05:06',
    'username' => 'must-not-escape',
    'profile_url' => '/u/must-not-escape',
    'collaborators' => [['username' => 'hidden-collaborator']],
];
$relatedData = [
    'tags' => ['one'],
    'references' => [['label' => 'Source', 'url' => 'https://example.test']],
    'related' => [['id' => 8, 'title' => 'Related', 'slug' => 'related-8', 'shared_tags' => 1]],
    'can_edit' => false,
    'can_manage' => false,
    'unknown_enrichment' => 'must-not-escape',
];

$authenticated = $projection->authenticatedDetail($raw, 7, $relatedData);
verifyPageProjection(array_keys($authenticated) === [
    'id', 'space_id', 'parent_id', 'title', 'slug', 'kind', 'content', 'position',
    'is_favorite', 'is_public', 'feature_image', 'feature_image_opacity', 'content_revision', 'created_at', 'updated_at',
    'tags', 'references', 'related', 'can_edit', 'can_manage', 'page_information',
], 'authenticated page details use an explicit stable response whitelist');
verifyPageProjection(!array_key_exists('author_id', $authenticated) && !array_key_exists('last_editor_id', $authenticated) && !array_key_exists('collaborators', $authenticated), 'authenticated details omit raw authorship IDs and collaborator-only data');
verifyPageProjection($authenticated['created_at'] === '2026-07-20T01:02:03Z' && $authenticated['updated_at'] === '2026-07-25T04:05:06Z', 'authenticated detail dates use canonical UTC metadata');
verifyPageProjection($authenticated['page_information']['author'] === [
    'state' => 'visible',
    'name' => 'Display Author',
    'profile_url' => '/u/stable-author-7',
    'avatar_url' => '/avatar/stable-author-7',
], 'authenticated details expose only the viewer-authorized display author projection');
verifyPageProjection(!str_contains(json_encode($authenticated, JSON_THROW_ON_ERROR), 'internal-login'), 'authenticated projections never serialize the author login username');

$public = $projection->publicDetail($raw);
verifyPageProjection($public['page'] === ['slug' => 'projected-page-42', 'title' => 'Projected page'], 'public templates receive only the public page identity they render');
verifyPageProjection(array_keys($public) === ['page', 'feature_image', 'page_information'] && !str_contains(json_encode($public, JSON_THROW_ON_ERROR), 'hidden-collaborator'), 'public projections omit repository internals and collaborator-only fields');
verifyPageProjection($public['feature_image'] === null, 'public projections report no feature image when the page has none');
verifyPageProjection($public['page_information']['first_published_at'] === '2026-07-24T08:09:10Z', 'public projections include sanitized first-publication metadata');

echo "\nn3 page projection service test passed.\n";
