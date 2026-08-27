<?php

declare(strict_types=1);

use N3\Core\Config\Environment;

$environment = Environment::oneOf('APP_ENV', ['local', 'test', 'production'], 'production');
$timezone = Environment::string('APP_TIMEZONE', 'UTC');

try {
    new DateTimeZone($timezone);
} catch (Throwable $exception) {
    throw new RuntimeException('APP_TIMEZONE must be a valid timezone identifier.', previous: $exception);
}

return [
    'name' => Environment::string('APP_NAME', 'N3'),
    'version' => '0.2.0',
    'environment' => $environment,
    'debug' => Environment::boolean('APP_DEBUG', false),
    'timezone' => $timezone,
];
