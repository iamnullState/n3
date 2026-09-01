<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use InvalidArgumentException;
use N3\Core\Module\ModuleResourcePolicy;
use N3\Core\Storage\ScopedModuleStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ModuleResourceTest extends TestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'n3-module-storage-');
        if ($path === false) {
            self::fail('Unable to allocate a temporary test path.');
        }
        unlink($path);
        mkdir($path, 0700);
        $this->temporaryRoot = $path;
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryRoot)) {
            $this->removeTree($this->temporaryRoot);
        }
    }

    public function testStorageIsScopedPrivateAndAtomic(): void
    {
        $storage = new ScopedModuleStorage($this->temporaryRoot, 'vendor/example', 'config');
        $storage->put('nested/settings.json', '{"enabled":true}');
        $path = $this->temporaryRoot . '/vendor/example/config/nested/settings.json';

        self::assertSame('{"enabled":true}', $storage->read('nested/settings.json'));
        self::assertTrue($storage->exists('nested/settings.json'));
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame([], glob(dirname($path) . '/.n3-*') ?: []);
    }

    #[DataProvider('unsafePaths')]
    public function testTraversalAndAmbiguousPathsAreRejected(string $path): void
    {
        $storage = new ScopedModuleStorage($this->temporaryRoot, 'vendor/example');

        $this->expectException(InvalidArgumentException::class);
        $storage->put($path, 'blocked');
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'parent' => ['../outside'];
        yield 'nested parent' => ['safe/../../outside'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'backslash' => ['safe\\outside'];
        yield 'empty segment' => ['safe//outside'];
        yield 'null byte' => ["safe\0outside"];
    }

    public function testSymbolicLinkPathsAreRejectedBeforeWriting(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('Symbolic links are unavailable.');
        }
        $outside = $this->temporaryRoot . '-outside';
        mkdir($outside, 0700);
        mkdir($this->temporaryRoot . '/vendor', 0700);
        symlink($outside, $this->temporaryRoot . '/vendor/example');

        try {
            $this->expectException(RuntimeException::class);
            (new ScopedModuleStorage($this->temporaryRoot, 'vendor/example'))->put('blocked.txt', 'blocked');
        } finally {
            if (is_link($this->temporaryRoot . '/vendor/example')) {
                unlink($this->temporaryRoot . '/vendor/example');
            }
            rmdir($outside);
        }
    }

    public function testModuleSchemaAndConfigNamespacesAreDeterministicAndBounded(): void
    {
        $prefix = ModuleResourcePolicy::schemaPrefix('vendor/a-very-long-module-name.with.parts');

        self::assertMatchesRegularExpression('/^m_[a-z0-9_]+_[a-f0-9]{8}_$/D', $prefix);
        self::assertLessThanOrEqual(64, strlen($prefix . 'records'));
        self::assertSame('modules.vendor.example.', ModuleResourcePolicy::configPrefix('vendor/example'));
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($path);
    }
}
