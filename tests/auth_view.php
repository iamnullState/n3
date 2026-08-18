<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\View\ViewRenderer;

function verifyAuthView(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$views = new ViewRenderer(dirname(__DIR__) . '/views');
$base = [
    'appName' => 'Test <wiki>',
    'button' => 'Sign in',
    'copy' => 'Sign in to your private wiki.',
    'error' => '',
    'mode' => 'login',
    'passwordAutocomplete' => 'current-password',
    'passwordMinlength' => 1,
    'setup' => false,
    'title' => 'Welcome back',
    'token' => 'csrf&token',
];

$login = $views->render('auth/form', $base);
verifyAuthView(str_contains($login, '<form method="post" action="/login">'), 'login view posts to the compatibility route');
verifyAuthView(str_contains($login, 'csrf&amp;token') && str_contains($login, 'Test &lt;wiki&gt;'), 'login view escapes CSRF and configuration values');
verifyAuthView(!str_contains($login, 'password_confirm'), 'login view omits password confirmation');

$setup = $views->render('auth/form', array_replace($base, [
    'button' => 'Create account',
    'error' => '<invalid>',
    'mode' => 'setup',
    'passwordAutocomplete' => 'new-password',
    'passwordMinlength' => 12,
    'setup' => true,
    'title' => 'Create your account',
]));
verifyAuthView(str_contains($setup, 'name="password_confirm"') && str_contains($setup, 'minlength="12"'), 'setup view includes its stronger password fields');
verifyAuthView(str_contains($setup, '&lt;invalid&gt;') && !str_contains($setup, '<invalid>'), 'authentication errors are safely escaped');

echo "\nn3 authentication view test passed.\n";
