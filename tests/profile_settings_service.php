<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\ProfileRepository;
use N3\Service\AccountService;
use N3\Service\DomainException;
use N3\Service\ProfileSettingsService;

function verifyProfileSettings(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

function expectProfileSettingsError(callable $operation, int $status, string $message): void
{
    try {
        $operation();
    } catch (DomainException $error) {
        verifyProfileSettings($error->status() === $status && $error->getMessage() === $message, "$status $message");
        return;
    }
    throw new RuntimeException("Expected profile settings error: $message");
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(<<<'SQL'
    CREATE TABLE users (
        id INTEGER PRIMARY KEY, username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL, session_version INTEGER NOT NULL DEFAULT 1,
        display_name TEXT NOT NULL DEFAULT '', biography TEXT NOT NULL DEFAULT '',
        profile_slug TEXT NOT NULL UNIQUE COLLATE NOCASE,
        profile_visibility TEXT NOT NULL DEFAULT 'private', avatar_reference TEXT,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
SQL);
$insert = $database->prepare('INSERT INTO users (id, username, password_hash, display_name, biography, profile_slug, profile_visibility, avatar_reference) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$insert->execute([1, 'Owner', password_hash('correct horse battery staple', PASSWORD_DEFAULT), 'Owner Name', 'Original bio', 'owner-1', 'private', str_repeat('a', 40) . '.png']);
$insert->execute([2, 'Taken', password_hash('another secure password', PASSWORD_DEFAULT), '', '', 'taken-2', 'private', null]);

$service = new ProfileSettingsService(new ProfileRepository($database), new AccountService($database));
$initial = $service->settings(1);
verifyProfileSettings(
    $initial === [
        'username' => 'Owner',
        'display_name' => 'Owner Name',
        'biography' => 'Original bio',
        'profile_slug' => 'owner-1',
        'profile_visibility' => 'private',
        'profile_url' => '/u/owner-1',
        'has_avatar' => true,
        'avatar_url' => '/avatar/owner-1',
    ],
    'self-service settings expose the approved profile projection without its storage reference',
);

$metadata = $service->update(1, 1, [
    'username' => 'Owner',
    'display_name' => '  Updated Name  ',
    'biography' => "  First line\nSecond line  ",
    'profile_visibility' => 'members',
]);
verifyProfileSettings(
    $metadata['display_name'] === 'Updated Name'
        && $metadata['biography'] === "First line\nSecond line"
        && $metadata['profile_visibility'] === 'members'
        && $metadata['session_version'] === 1
        && !$metadata['session_rotated'],
    'display identity, biography, and visibility update without requiring a password or rotating the session',
);
verifyProfileSettings($metadata['profile_slug'] === 'owner-1', 'profile updates preserve the immutable profile slug');

expectProfileSettingsError(fn() => $service->update(1, 1, [
    'username' => 'Renamed', 'display_name' => '', 'biography' => '', 'profile_visibility' => 'public',
]), 403, 'Current password is incorrect.');
verifyProfileSettings($service->settings(1)['username'] === 'Owner', 'a rejected username change leaves all profile settings intact');

$renamed = $service->update(1, 1, [
    'username' => 'Renamed',
    'display_name' => 'Updated Name',
    'biography' => 'Public introduction',
    'profile_visibility' => 'public',
    'current_password' => 'correct horse battery staple',
]);
verifyProfileSettings(
    $renamed['username'] === 'Renamed'
        && $renamed['profile_slug'] === 'owner-1'
        && $renamed['session_version'] === 2
        && $renamed['session_rotated'],
    'password-confirmed username changes rotate sessions without changing the profile URL',
);

expectProfileSettingsError(fn() => $service->update(1, 2, [
    'username' => 'Taken', 'display_name' => '', 'biography' => '', 'profile_visibility' => 'private',
    'current_password' => 'correct horse battery staple',
]), 409, 'That username is unavailable.');
expectProfileSettingsError(fn() => $service->update(1, 2, [
    'username' => 'Renamed', 'display_name' => '', 'biography' => '', 'profile_visibility' => 'friends',
]), 422, 'Choose a valid profile visibility.');
expectProfileSettingsError(fn() => $service->update(1, 2, [
    'username' => 'Renamed', 'display_name' => str_repeat('x', 81), 'biography' => '', 'profile_visibility' => 'private',
]), 422, 'Keep the display name to 80 characters or fewer.');
expectProfileSettingsError(fn() => $service->update(1, 1, [
    'username' => 'Renamed', 'display_name' => '', 'biography' => '', 'profile_visibility' => 'private',
]), 409, 'The profile changed in another session. Refresh and try again.');
expectProfileSettingsError(fn() => $service->settings(99), 404, 'Profile not found.');

echo "\nn3 profile settings service test passed.\n";
