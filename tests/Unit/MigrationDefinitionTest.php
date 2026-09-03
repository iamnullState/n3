<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Database\Migration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MigrationDefinitionTest extends TestCase
{
    #[DataProvider('migrationFiles')]
    public function testMigrationMatchesItsFilename(string $file): void
    {
        $migration = require $file;

        self::assertInstanceOf(Migration::class, $migration);
        self::assertSame(pathinfo($file, PATHINFO_FILENAME), $migration->version());
    }

    /** @return iterable<string, array{string}> */
    public static function migrationFiles(): iterable
    {
        $path = dirname(__DIR__, 2) . '/database/migrations/';
        yield 'users' => [$path . '202608270001_create_users.php'];
        yield 'identity security' => [$path . '202608270002_create_identity_security.php'];
        yield 'authentication recovery' => [$path . '202608270003_create_authentication_recovery.php'];
        yield 'pages' => [$path . '202608300004_create_pages.php'];
        yield 'module lifecycle and jobs' => [$path . '202608300005_create_module_lifecycle_and_jobs.php'];
        yield 'webhook receipts' => [$path . '202608310006_create_webhook_receipts.php'];
        yield 'module migrations' => [$path . '202608310007_create_module_migrations.php'];
        yield 'site scaffold' => [$path . '202609020008_create_site_scaffold.php'];
        yield 'installation state' => [$path . '202609020009_create_installation_state.php'];
        yield 'browser installation state' => [$path . '202609020010_extend_installation_state.php'];
    }

    public function testHistoricalMigrationChecksumsRemainFrozen(): void
    {
        $path = dirname(__DIR__, 2) . '/database/migrations/';
        $expected = [
            '202608270001_create_users.php' => '7f96c459ee7405c58d5125dc42785b5497ea087f2ada2ed5fa9f42d97947f4a2',
            '202608270002_create_identity_security.php' => '96a917a3aafca71637d22c959648e9e083d2ede854fba4e98eabd697ff96d72c',
            '202608270003_create_authentication_recovery.php' => '36e0514ac49b9562c9463d2dd6f4d839c65eb51561d98cbd86317a1b13bee082',
            '202608300004_create_pages.php' => '091f11e073f9b81c7c42b16b981446fd96c58dbc31495bf8c4d1501eaf430f08',
            '202608300005_create_module_lifecycle_and_jobs.php' => '13a6c50dd20e833cfa1de1c1ad0181e757c6cda48e3c7d414f895bf3f9812998',
            '202608310006_create_webhook_receipts.php' => 'e22f3ef6dd13afcb5401cd6d22224b0871a781ae2661ceb23d0a23b48c0a91b0',
            '202608310007_create_module_migrations.php' => '2ebfe5d064ef5793e77541af0cb0072170ae834eca8557e6df1d8da0e35adce5',
            '202609020008_create_site_scaffold.php' => '9b8f9d8f56c9be580191ef6ff2c1c0394fde1924ec9126969d907637abf87f85',
            '202609020009_create_installation_state.php' => '0053e18f1ec50721e6f4f7abfdda555ea20cb9028d66f48dc81af5d4fd3938f4',
        ];

        foreach ($expected as $file => $checksum) {
            self::assertSame($checksum, hash_file('sha256', $path . $file), $file);
        }
    }
}
