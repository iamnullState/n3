<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use LogicException;
use N3\Core\Service\ServiceRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class ServiceRegistryTest extends TestCase
{
    public function testItRegistersAndReturnsAnExplicitService(): void
    {
        $registry = new ServiceRegistry();
        $service = new stdClass();

        $registry->register('example.service', $service);

        self::assertTrue($registry->has('example.service'));
        self::assertSame($service, $registry->get('example.service'));
    }

    public function testItRejectsDuplicateAndLateRegistrations(): void
    {
        $registry = new ServiceRegistry();
        $registry->register('example.service', new stdClass());

        try {
            $registry->register('example.service', new stdClass());
            self::fail('A duplicate service was accepted.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('already registered', $exception->getMessage());
        }

        $registry->freeze();

        $this->expectException(LogicException::class);
        $registry->register('late.service', new stdClass());
    }

    public function testItRejectsUnknownServices(): void
    {
        $this->expectException(RuntimeException::class);

        (new ServiceRegistry())->get('missing.service');
    }
}
