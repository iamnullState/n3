<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use LogicException;
use N3\Core\Database\TransactionManager;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransactionManagerTest extends TestCase
{
    private PDO $connection;

    protected function setUp(): void
    {
        $this->connection = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->connection->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    }

    public function testItCommitsAndReturnsTheOperationResult(): void
    {
        $result = (new TransactionManager($this->connection))->run(
            function (PDO $connection): string {
                $connection->exec("INSERT INTO records (value) VALUES ('committed')");

                return 'result';
            },
        );

        self::assertSame('result', $result);
        self::assertSame(1, (int) $this->connection->query('SELECT COUNT(*) FROM records')->fetchColumn());
        self::assertFalse($this->connection->inTransaction());
    }

    public function testItRollsBackAndRethrowsTheOriginalFailure(): void
    {
        try {
            (new TransactionManager($this->connection))->run(
                function (PDO $connection): never {
                    $connection->exec("INSERT INTO records (value) VALUES ('rolled back')");

                    throw new LogicException('operation failed');
                },
            );

            self::fail('The transaction failure was not rethrown.');
        } catch (LogicException $exception) {
            self::assertSame('operation failed', $exception->getMessage());
        }

        self::assertSame(0, (int) $this->connection->query('SELECT COUNT(*) FROM records')->fetchColumn());
        self::assertFalse($this->connection->inTransaction());
    }

    public function testItRejectsNestedTransactionsWithoutChangingTheOuterTransaction(): void
    {
        $this->connection->beginTransaction();

        try {
            (new TransactionManager($this->connection))->run(static fn (): null => null);
            self::fail('A nested transaction was accepted.');
        } catch (RuntimeException $exception) {
            self::assertSame('Nested database transactions are not supported.', $exception->getMessage());
            self::assertTrue($this->connection->inTransaction());
        } finally {
            $this->connection->rollBack();
        }

        self::assertFalse($this->connection->inTransaction());
    }
}
