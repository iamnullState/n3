<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\DatabaseException;
use PHPUnit\Framework\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    public function testMissingMariaDbDriverFailsWithAControlledMessage(): void
    {
        $factory = new ConnectionFactory();

        if ($factory->supportsMariaDb()) {
            $this->markTestSkipped('pdo_mysql is installed in this environment.');
        }

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('pdo_mysql');

        $factory->create(new DatabaseConfig('127.0.0.1', 3306, 'n3', 'n3_app', 'secret'));
    }
}
