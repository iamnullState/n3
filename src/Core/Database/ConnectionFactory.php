<?php

declare(strict_types=1);

namespace N3\Core\Database;

use PDO;
use PDOException;

final class ConnectionFactory
{
    public function supportsMariaDb(): bool
    {
        return in_array('mysql', PDO::getAvailableDrivers(), true);
    }

    public function create(DatabaseConfig $config): PDO
    {
        if (!$this->supportsMariaDb()) {
            throw new DatabaseException('The pdo_mysql PHP extension is required for MariaDB.');
        }

        try {
            return new PDO(
                $config->dsn(),
                $config->username,
                $config->password(),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ],
            );
        } catch (PDOException $exception) {
            throw new DatabaseException('Unable to connect to MariaDB.', previous: $exception);
        }
    }
}
