<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Service\AccountService;
use N3\Service\AuthService;
use N3\Service\DomainException;

function verifyAccountService(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

function expectAccountError(callable $operation, int $status, string $message): void
{
    try {
        $operation();
    } catch (DomainException $error) {
        verifyAccountService($error->status() === $status && $error->getMessage() === $message, "$status $message");
        return;
    }
    throw new RuntimeException("Expected domain error: $message");
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(<<<'SQL'
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL,
        session_version INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE auth_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip_address TEXT NOT NULL,
        attempted_at INTEGER NOT NULL
    );
SQL);

$auth = new AuthService($database);
verifyAccountService(!$auth->accountExists(), 'authentication service reports an empty owner store');
$ownerId = $auth->createOwner('Owner', 'correct horse battery staple');
verifyAccountService($ownerId === 1 && $auth->accountExists(), 'authentication service creates the single owner');
verifyAccountService($auth->createOwner('second', 'another secure password') === null, 'authentication service cannot create a second owner');

$credentials = $auth->findByUsername('owner');
verifyAccountService($credentials !== null && password_verify('correct horse battery staple', $credentials['password_hash']), 'username lookup is case-insensitive and returns a verifiable hash');
$sessionUser = $auth->findSessionUser($ownerId);
verifyAccountService($sessionUser === ['id' => 1, 'username' => 'Owner', 'session_version' => 1, 'is_admin' => 0], 'session lookup exposes session and administration fields without credentials');
verifyAccountService($auth->findByUsername('missing') === null && $auth->findSessionUser(99) === null, 'authentication lookups return null for unknown owners');

$ip = '192.0.2.10';
$database->prepare('INSERT INTO auth_attempts (ip_address, attempted_at) VALUES (?, ?)')->execute([$ip, time() - 901]);
for ($attempt = 0; $attempt < 7; $attempt++) $auth->recordFailedLogin($ip);
verifyAccountService(!$auth->isRateLimited($ip), 'login throttling removes expired attempts and allows fewer than eight failures');
$auth->recordFailedLogin($ip);
verifyAccountService($auth->isRateLimited($ip), 'login throttling activates at eight recent failures');
$auth->clearFailedLogins($ip);
verifyAccountService(!$auth->isRateLimited($ip), 'successful authentication clears the throttle state');

$oldHash = $credentials['password_hash'];
$auth->rehashPassword($ownerId, 'correct horse battery staple');
$rehash = $auth->findByUsername('Owner')['password_hash'];
verifyAccountService($rehash !== $oldHash && password_verify('correct horse battery staple', $rehash), 'password rehashing replaces the hash without changing the password');

$accounts = new AccountService($database);
verifyAccountService($accounts->passwordHash($ownerId) === $rehash, 'account service returns the stored owner hash');
expectAccountError(fn() => $accounts->changeCredentials($ownerId, 1, 'wrong', 'Renamed', ''), 403, 'Current password is incorrect.');
expectAccountError(fn() => $accounts->changeCredentials($ownerId, 1, 'correct horse battery staple', '   ', ''), 422, 'Username is required.');
expectAccountError(fn() => $accounts->changeCredentials($ownerId, 1, 'correct horse battery staple', 'Renamed', 'short'), 422, 'Use at least 12 characters for the new password.');

$result = $accounts->changeCredentials($ownerId, 1, 'correct horse battery staple', '  Renamed  ', 'new correct horse battery staple');
$updated = $auth->findSessionUser($ownerId);
verifyAccountService($result === ['username' => 'Renamed', 'session_version' => 2] && $updated === ['id' => 1, 'username' => 'Renamed', 'session_version' => 2, 'is_admin' => 0], 'credential updates normalize username and invalidate prior sessions together');
verifyAccountService(password_verify('new correct horse battery staple', $accounts->passwordHash($ownerId)), 'credential updates persist the replacement password hash');

expectAccountError(fn() => $accounts->invalidateOtherSessions($ownerId, 2, 'wrong'), 403, 'Current password is incorrect.');
$nextVersion = $accounts->invalidateOtherSessions($ownerId, 2, 'new correct horse battery staple');
verifyAccountService($nextVersion === 3 && (int)$auth->findSessionUser($ownerId)['session_version'] === 3, 'global session invalidation verifies credentials and advances the session version');

$database->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')->execute(['Taken', password_hash('irrelevant password', PASSWORD_DEFAULT)]);
expectAccountError(fn() => $accounts->changeCredentials($ownerId, 3, 'new correct horse battery staple', 'taken', ''), 409, 'That username is unavailable.');

echo "\nn3 account service test passed.\n";
