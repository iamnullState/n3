<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Database\DatabaseException;
use N3\Core\Database\TableNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TableNamesTest extends TestCase
{
    public function testEmptyPrefixPreservesExistingIdentifiers(): void
    {
        $names = new TableNames();

        self::assertSame('users', $names->physical('users'));
        self::assertSame('SELECT * FROM users', $names->rewrite('SELECT * FROM users'));
    }

    public function testPrefixRewritesOnlyManagedBareAndQuotedIdentifiers(): void
    {
        $names = new TableNames('client_');
        $sql = "SELECT users.id FROM users JOIN `m_n3_blog_0356bd27_posts` p ON p.author_id = users.id "
            . "WHERE p.title = 'users' AND p.slug = \"pages\"";

        self::assertSame(
            "SELECT client_users.id FROM client_users JOIN `client_m_n3_blog_0356bd27_posts` p ON p.author_id = client_users.id "
            . "WHERE p.title = 'users' AND p.slug = \"pages\"",
            $names->rewrite($sql),
        );
    }

    public function testUnknownIdentifiersCannotBeResolvedDirectly(): void
    {
        $this->expectException(DatabaseException::class);

        (new TableNames('client_'))->physical('request_selected_table');
    }

    #[DataProvider('invalidPrefixes')]
    public function testInvalidPrefixesFailClosed(string $prefix): void
    {
        $this->expectException(DatabaseException::class);

        new TableNames($prefix);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPrefixes(): iterable
    {
        yield 'missing trailing underscore' => ['client'];
        yield 'uppercase' => ['Client_'];
        yield 'leading number' => ['1client_'];
        yield 'hyphen' => ['client-site_'];
        yield 'too long' => [str_repeat('a', 24) . '_'];
        yield 'underscore only' => ['_'];
    }
}
