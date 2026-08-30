<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use InvalidArgumentException;
use N3\Core\Module\ModuleManifest;
use N3\Core\Module\VersionConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleManifestTest extends TestCase
{
    #[DataProvider('constraints')]
    public function testSupportedConstraintsAreDeterministic(string $version, string $constraint, bool $matches): void
    {
        self::assertSame($matches, VersionConstraint::matches($version, $constraint));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function constraints(): iterable
    {
        yield 'exact match' => ['0.2.0', '0.2.0', true];
        yield 'exact mismatch' => ['0.2.1', '0.2.0', false];
        yield 'zero-major caret stays in minor' => ['0.2.9', '^0.2', true];
        yield 'zero-major caret rejects next minor' => ['0.3.0', '^0.2', false];
        yield 'stable caret accepts later minor' => ['1.9.0', '^1.2', true];
        yield 'stable caret rejects next major' => ['2.0.0', '^1.2', false];
    }

    public function testManifestRejectsInvalidModuleIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ModuleManifest('Unsafe Module', '1.0.0', '^0.2');
    }

    public function testManifestRejectsUnsupportedConstraintSyntax(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ModuleManifest('test/module', '1.0.0', '>=0.2');
    }
}
