<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use N3\Config;

function verifyProxy(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$originalEnvironment = [
    'APP_URL' => getenv('APP_URL'),
    'TRUSTED_PROXY_IPS' => getenv('TRUSTED_PROXY_IPS'),
];
$originalServer = $_SERVER;

try {
    putenv('APP_URL=https://n3.example.test');
    putenv('TRUSTED_PROXY_IPS=100.64.0.10, invalid, 2001:db8::10');
    verifyProxy(Config::publicHttps(), 'the external HTTPS origin marks session cookies secure');
    verifyProxy(Config::trustedProxyIps() === ['100.64.0.10', '2001:db8::10'], 'trusted proxies accept only explicit valid IP addresses');

    putenv('APP_URL=http://localhost:8786');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.8';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
    verifyProxy(!requestIsHttps(), 'forwarded HTTPS is ignored from an untrusted sender');
    verifyProxy(requestClientIp() === '198.51.100.8', 'forwarded client IP is ignored from an untrusted sender');

    $_SERVER['REMOTE_ADDR'] = '100.64.0.10';
    verifyProxy(requestIsHttps(), 'forwarded HTTPS is accepted from the configured proxy');
    verifyProxy(requestClientIp() === '203.0.113.9', 'login throttling receives the forwarded client IP from the configured proxy');
} finally {
    foreach ($originalEnvironment as $name => $value) putenv($value === false ? $name : "$name=$value");
    $_SERVER = $originalServer;
}

echo "\nn3 proxy configuration test passed.\n";
