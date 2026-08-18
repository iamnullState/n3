<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\ProfileRepository;
use N3\Service\DomainException;
use N3\Service\ProfileAvatarService;

function verifyAvatar(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

function expectAvatarError(callable $operation, int $status, string $message): void
{
    try {
        $operation();
    } catch (DomainException $error) {
        verifyAvatar($error->status() === $status && $error->getMessage() === $message, "$status $message");
        return;
    }
    throw new RuntimeException("Expected avatar error: $message");
}

function avatarUpload(string $path, ?int $size = null): array
{
    return [
        'name' => '../../client-supplied.php.png',
        'type' => 'application/x-php',
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => $size ?? filesize($path),
    ];
}

function removeAvatarFixture(string $directory): void
{
    if (!is_dir($directory)) return;
    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
        $path = $directory . '/' . $entry;
        is_dir($path) ? removeAvatarFixture($path) : unlink($path);
    }
    rmdir($directory);
}

$temp = sys_get_temp_dir() . '/n3-avatar-' . bin2hex(random_bytes(5));
mkdir($temp, 0700, true);

try {
    $database = new PDO('sqlite::memory:');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $database->exec(<<<'SQL'
        CREATE TABLE users (
            id INTEGER PRIMARY KEY, username TEXT NOT NULL, display_name TEXT NOT NULL DEFAULT '',
            biography TEXT NOT NULL DEFAULT '', profile_slug TEXT UNIQUE COLLATE NOCASE,
            profile_visibility TEXT NOT NULL DEFAULT 'private', avatar_reference TEXT,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        INSERT INTO users (id, username, profile_slug, profile_visibility) VALUES
            (1, 'owner', 'owner-1', 'private'),
            (2, 'member', 'member-2', 'members'),
            (3, 'public', 'public-3', 'public'),
            (4, 'viewer', 'viewer-4', 'private');
    SQL);

    $profiles = new ProfileRepository($database);
    $service = new ProfileAvatarService(
        $profiles,
        $temp,
        static fn(string $path): bool => is_file($path),
        static fn(string $source, string $target): bool => rename($source, $target),
    );
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    if (!is_string($png)) throw new RuntimeException('Could not decode avatar fixture.');

    $firstUpload = $temp . '/first-upload';
    file_put_contents($firstUpload, $png);
    $stored = $service->storeForUser(1, avatarUpload($firstUpload));
    $firstReference = $profiles->avatarReferenceForUser(1);
    verifyAvatar(
        $stored['mime'] === 'image/png'
            && $stored['width'] === 1
            && $stored['height'] === 1
            && preg_match('/^[a-f0-9]{40}\.png$/D', (string)$firstReference) === 1
            && is_file($temp . '/avatars/' . $firstReference),
        'avatar storage uses server-inspected content and an opaque randomized filename',
    );
    verifyAvatar(!array_key_exists('reference', $stored) && !str_contains(json_encode($stored), 'client-supplied'), 'avatar results do not expose storage references or client filenames');

    verifyAvatar($service->findForProfile('owner-1', 1) !== null, 'profile owners can retrieve their private avatar');
    verifyAvatar($service->findForProfile('owner-1', 4) === null && $service->findForProfile('owner-1', null) === null, 'private avatars are hidden from other signed-in and anonymous viewers');
    $database->exec("UPDATE users SET profile_visibility = 'members' WHERE id = 1");
    verifyAvatar($service->findForProfile('owner-1', 4) !== null && $service->findForProfile('owner-1', null) === null, 'members-only avatars require a signed-in viewer');
    $database->exec("UPDATE users SET profile_visibility = 'public' WHERE id = 1");
    verifyAvatar($service->findForProfile('OWNER-1', null) !== null, 'public avatars are available anonymously through case-insensitive profile slugs');
    verifyAvatar($service->findForProfile('missing', null) === null, 'missing and unauthorized avatar lookups share the same empty result');
    file_put_contents($temp . '/avatars/' . $firstReference, "<?php", FILE_APPEND);
    verifyAvatar($service->findForProfile('owner-1', null) === null, 'delivery rejects stored images that fail complete encoding validation');
    file_put_contents($temp . '/avatars/' . $firstReference, $png);

    $invalidUpload = $temp . '/executable-upload';
    file_put_contents($invalidUpload, "<?php echo 'unsafe'; ?>");
    expectAvatarError(fn() => $service->storeForUser(1, avatarUpload($invalidUpload)), 422, 'Use a JPEG, PNG, GIF, or WebP avatar image.');
    verifyAvatar($profiles->avatarReferenceForUser(1) === $firstReference && is_file($temp . '/avatars/' . $firstReference), 'a rejected replacement preserves the existing avatar');

    $fakeImage = $temp . '/invalid-image';
    file_put_contents($fakeImage, substr($png, 0, -12));
    expectAvatarError(fn() => $service->storeForUser(1, avatarUpload($fakeImage)), 422, 'The avatar image could not be read.');

    $wideUpload = $temp . '/wide-upload';
    $widePng = substr_replace($png, pack('N', 5000), 16, 4);
    $widePng = substr_replace($widePng, hash('crc32b', substr($widePng, 12, 17), true), 29, 4);
    file_put_contents($wideUpload, $widePng);
    expectAvatarError(fn() => $service->storeForUser(1, avatarUpload($wideUpload)), 422, 'The avatar dimensions exceed the 4096 × 4096 pixel limit.');

    $oversizeUpload = $temp . '/oversize-upload';
    file_put_contents($oversizeUpload, $png . str_repeat("\0", ProfileAvatarService::MAX_BYTES));
    expectAvatarError(fn() => $service->storeForUser(1, avatarUpload($oversizeUpload)), 422, 'The avatar is larger than the 5 MB limit.');

    expectAvatarError(
        fn() => $service->storeForUser(1, ['error' => UPLOAD_ERR_INI_SIZE]),
        422,
        'The avatar is larger than the 5 MB limit.',
    );

    $replacementUpload = $temp . '/replacement-upload';
    file_put_contents($replacementUpload, $png);
    $service->storeForUser(1, avatarUpload($replacementUpload));
    $replacementReference = $profiles->avatarReferenceForUser(1);
    verifyAvatar($replacementReference !== $firstReference && !is_file($temp . '/avatars/' . $firstReference), 'successful replacement removes the superseded avatar safely');
    verifyAvatar($service->removeForUser(1) && $profiles->avatarReferenceForUser(1) === null && !is_file($temp . '/avatars/' . $replacementReference), 'avatar removal clears the database reference and stored file');
    verifyAvatar(!$service->removeForUser(1), 'removing an absent avatar is idempotent');

    $database->exec("UPDATE users SET avatar_reference = '../outside.png' WHERE id = 1");
    verifyAvatar($service->findForProfile('owner-1', 1) === null, 'invalid stored references cannot traverse outside the avatar directory');
} finally {
    removeAvatarFixture($temp);
}

echo "\nn3 profile avatar service test passed.\n";
