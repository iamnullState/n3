<?php

declare(strict_types=1);

namespace N3\Core\Database;

use PDO;
use RuntimeException;
use Throwable;

final readonly class TransactionManager
{
    public function __construct(private PDO $connection)
    {
    }

    public function run(callable $operation): mixed
    {
        if ($this->connection->inTransaction()) {
            throw new RuntimeException('Nested database transactions are not supported.');
        }

        $this->connection->beginTransaction();

        try {
            $result = $operation($this->connection);
            $this->connection->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}
