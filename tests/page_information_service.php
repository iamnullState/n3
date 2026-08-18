<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\ProfileRepository;
use N3\Service\PageInformationService;

function verifyPageInformation(bool $condition, string $message): void
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
    INSERT INTO users VALUES
        (1, 'private-user', 'Private Person', 'private-user-1', 'private', 'private.png'),
        (2, 'member-user', '', 'member-user-2', 'members', NULL),
        (3, 'public-user', 'Public Person', 'public-user-3', 'public', 'public.png');
SQL);

$service = new PageInformationService(new ProfileRepository($database));
$page = [
    'author_id' => 1,
    'content' => '<p>One &amp; two</p><p>three</p>',
    'created_at' => '2026-07-20 10:00:00',
    'first_published_at' => null,
    'updated_at' => '2026-07-25 12:30:00',
];

$self = $service->forPage($page, 1);
verifyPageInformation($self['author'] === [
    'state' => 'visible',
    'name' => 'Private Person',
    'profile_url' => '/u/private-user-1',
    'avatar_url' => '/avatar/private-user-1',
], 'authors can see their own private identity with authorized profile and avatar links');
verifyPageInformation($self['word_count'] === 4, 'word counts decode entities and preserve boundaries between HTML blocks');
verifyPageInformation($self['created_at'] === '2026-07-20T10:00:00Z' && $self['first_published_at'] === null && $self['updated_at'] === '2026-07-25T12:30:00Z', 'page information canonicalizes valid UTC dates and omits an absent publication date');

$privateVisitor = $service->forPage($page, 2);
verifyPageInformation($privateVisitor['author'] === [
    'state' => 'private',
    'name' => 'Private author',
    'profile_url' => null,
    'avatar_url' => null,
], 'private authors use a non-linking fallback for other signed-in viewers');

$memberPage = $page;
$memberPage['author_id'] = 2;
$memberVisitor = $service->forPage($memberPage, 1);
verifyPageInformation($memberVisitor['author']['state'] === 'visible' && $memberVisitor['author']['name'] === 'member-user' && $memberVisitor['author']['avatar_url'] === null, 'members profiles expose only display identity and authorized profile links to signed-in viewers');
verifyPageInformation($service->forPage($memberPage, null)['author']['state'] === 'private', 'members profiles remain hidden from anonymous page information');

$publicPage = $page;
$publicPage['author_id'] = 3;
$publicPage['first_published_at'] = '2026-07-22 09:00:00';
$public = $service->forPage($publicPage, null);
verifyPageInformation($public['author']['name'] === 'Public Person' && $public['author']['profile_url'] === '/u/public-user-3' && $public['author']['avatar_url'] === '/avatar/public-user-3', 'public profiles expose their permitted identity links anonymously');
verifyPageInformation($public['first_published_at'] === '2026-07-22T09:00:00Z', 'published page information includes the canonical write-once first-publication date');

$unknownPage = $page;
$unknownPage['author_id'] = null;
verifyPageInformation($service->forPage($unknownPage, null)['author'] === [
    'state' => 'unknown',
    'name' => 'Unknown author',
    'profile_url' => null,
    'avatar_url' => null,
], 'missing authors use the non-linking unknown-author fallback');

$missingPage = $page;
$missingPage['author_id'] = 999;
verifyPageInformation($service->forPage($missingPage, 1)['author']['state'] === 'unknown', 'deleted author records cannot leave profile or avatar links behind');

$invalidDates = $page;
$invalidDates['created_at'] = 'not-a-date';
$invalidDates['updated_at'] = '<script>alert(1)</script>';
verifyPageInformation($service->forPage($invalidDates, 1)['created_at'] === null && $service->forPage($invalidDates, 1)['updated_at'] === null, 'invalid date metadata is omitted instead of crossing the response boundary');

echo "\nn3 page information service test passed.\n";
