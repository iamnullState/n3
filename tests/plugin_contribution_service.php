<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Plugin\PluginRegistrationException;
use N3\Plugin\PluginRegistry;
use N3\Service\PluginContributionService;

function verifyPluginContribution(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$captured = [];
$registry = new PluginRegistry();
$registration = $registry->begin([
    'id' => 'context-probe',
    'name' => 'Context probe',
    'version' => '1.0.0',
    'css' => [],
    'js' => [],
    'dashboard' => [],
    'contribution_slots' => ['profile_tools', 'profile_cards', 'page_information'],
]);
$registry->profileTool(static function (array $context) use (&$captured): array {
    $captured['profile'] = $context;
    return ['label' => "Safe\nprofile tool", 'url' => '/api/plugins/context-probe/profile'];
});
$registry->profileCard(static fn(array $context): array => [
    ['title' => '<Card>', 'body' => '<script>text only</script>', 'url' => '/api/plugins/context-probe/card'],
    ['title' => 'Rejected URL', 'body' => 'Cannot link outside its namespace.', 'url' => '/private/resource'],
]);
$registry->pageInformationRow(static function (array $context) use (&$captured): array {
    $captured['page'] = $context;
    return ['label' => '<State>', 'value' => $context['page']['can_edit'] ? 'Editable' : 'Read only'];
});
$registry->commit($registration);

$undeclared = new PluginRegistry();
$undeclaredRegistration = $undeclared->begin(['id' => 'undeclared', 'contribution_slots' => []]);
$undeclaredRejected = false;
try {
    $undeclared->profileTool(static fn(array $context): array => []);
} catch (PluginRegistrationException) {
    $undeclaredRejected = true;
} finally {
    $undeclared->discard($undeclaredRegistration);
}
verifyPluginContribution($undeclaredRejected, 'contribution handlers require an explicit manifest slot declaration');

$service = new PluginContributionService($registry);
$profile = $service->forProfile([
    'id' => 7,
    'username' => 'private-login',
    'display_name' => 'Visible name',
    'biography' => 'Private biography',
    'profile_slug' => 'visible-profile-7',
    'profile_visibility' => 'members',
    'avatar_reference' => 'private-file.png',
    'has_avatar' => true,
    'audience' => 'signed_in',
    'is_self' => false,
    'pages' => ['authored' => [['id' => 99, 'title' => 'Private title']]],
    'counts' => ['authored' => 1],
]);
verifyPluginContribution(
    $captured['profile'] === [
        'surface' => 'profile',
        'audience' => 'signed_in',
        'profile' => [
            'display_name' => 'Visible name',
            'profile_url' => '/u/visible-profile-7',
            'avatar_url' => '/avatar/visible-profile-7',
            'visibility' => 'members',
            'is_self' => false,
            'page_counts' => ['authored' => 1],
        ],
    ],
    'profile plugins receive only viewer-authorized allowlisted context without IDs, usernames, biographies, storage fields, or page records',
);
verifyPluginContribution(
    $profile['tools'][0]['label'] === 'Safe profile tool'
        && $profile['cards'][0]['url'] === '/api/plugins/context-probe/card'
        && $profile['cards'][1]['url'] === null,
    'profile contribution output is bounded, text-only, attributed, and limited to the plugin API namespace',
);
verifyPluginContribution(
    $service->forProfile(['audience' => 'public']) === ['tools' => [], 'cards' => []],
    'authenticated profile contributions are omitted from public profile projections',
);

$rows = $service->pageInformationRows([
    'id' => 42,
    'slug' => 'safe-page-42',
    'title' => 'Viewer-safe page',
    'content' => 'private body',
    'author_id' => 7,
    'is_public' => 0,
], [
    'author' => ['state' => 'private', 'name' => 'Private author', 'profile_url' => null, 'avatar_url' => null],
    'word_count' => 2,
    'created_at' => '2026-07-25T00:00:00Z',
    'first_published_at' => null,
    'updated_at' => '2026-07-25T01:00:00Z',
], ['can_edit' => false, 'can_manage' => false]);
verifyPluginContribution(
    !isset($captured['page']['page']['id'], $captured['page']['page']['content'], $captured['page']['page']['author_id'])
        && $captured['page']['information']['author']['state'] === 'private'
        && $captured['page']['information']['author']['profile_url'] === null
        && $rows[0]['label'] === '<State>'
        && $rows[0]['value'] === 'Read only',
    'page-information plugins receive the authorized metadata projection without raw page or author fields',
);

echo "\nn3 plugin contribution service test passed.\n";
