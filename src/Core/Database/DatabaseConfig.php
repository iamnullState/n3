<?php

declare(strict_types=1);

namespace N3\Core\Database;

use N3\Core\Config\Environment;

final readonly class DatabaseConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        private string $password,
    ) {
        if (!$this->isValidHost($host)) {
            throw new DatabaseException('DB_HOST must be a valid hostname or IP address.');
        }

        if ($port < 1 || $port > 65535) {
            throw new DatabaseException('DB_PORT must be between 1 and 65535.');
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new DatabaseException('DB_NAME may contain only letters, numbers, and underscores.');
        }

        if ($username === '' || strlen($username) > 128) {
            throw new DatabaseException('DB_USER must be between 1 and 128 bytes.');
        }

        if ($password === '') {
            throw new DatabaseException('DB_PASSWORD must not be empty.');
        }
    }

    public static function fromEnvironment(): self
    {
        return self::fromCredentialKeys('DB_USER', 'DB_PASSWORD');
    }

    public static function fromMigrationEnvironment(): self
    {
        return self::fromCredentialKeys('DB_MIGRATION_USER', 'DB_MIGRATION_PASSWORD');
    }

    private static function fromCredentialKeys(string $userKey, string $passwordKey): self
    {
        $port = filter_var(
            Environment::string('DB_PORT', '3306'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]],
        );

        if ($port === false) {
            throw new DatabaseException('DB_PORT must be an integer between 1 and 65535.');
        }

        return new self(
            host: Environment::string('DB_HOST', '127.0.0.1'),
            port: $port,
            database: Environment::string('DB_NAME', 'n3'),
            username: Environment::string($userKey),
            password: Environment::string($passwordKey),
        );
    }

    public function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->database,
        );
    }

    public function password(): string
    {
        return $this->password;
    }

    private function isValidHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return strlen($host) <= 253
            && preg_match(
                '/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/',
                $host,
            ) === 1;
    }
}
