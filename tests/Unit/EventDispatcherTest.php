<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use LogicException;
use N3\Core\Event\CoreStarted;
use N3\Core\Event\EventDispatcher;
use N3\Core\Event\EventListenerFailed;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EventDispatcherTest extends TestCase
{
    public function testItDispatchesSynchronouslyByPriorityThenRegistrationOrder(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = [];
        $dispatcher->listen(CoreStarted::class, 'n3/first', 'first', static function () use (&$calls): void {
            $calls[] = 'first';
        });
        $dispatcher->listen(CoreStarted::class, 'n3/high', 'high', static function () use (&$calls): void {
            $calls[] = 'high';
        }, 10);
        $dispatcher->listen(CoreStarted::class, 'n3/second', 'second', static function () use (&$calls): void {
            $calls[] = 'second';
        });
        $dispatcher->seal();

        $dispatcher->dispatch(new CoreStarted('0.2.0', new \DateTimeImmutable('2026-08-30T00:00:00Z')));

        self::assertSame(['high', 'first', 'second'], $calls);
    }

    public function testListenerFailureIsAttributedAndStopsDispatch(): void
    {
        $dispatcher = new EventDispatcher();
        $continued = false;
        $dispatcher->listen(CoreStarted::class, 'n3/failing', 'explode', static function (): void {
            throw new RuntimeException('private implementation detail');
        });
        $dispatcher->listen(CoreStarted::class, 'n3/later', 'later', static function () use (&$continued): void {
            $continued = true;
        });
        $dispatcher->seal();

        try {
            $dispatcher->dispatch(new CoreStarted('0.2.0', new \DateTimeImmutable()));
            self::fail('A listener exception was not reported.');
        } catch (EventListenerFailed $exception) {
            self::assertSame('n3/failing', $exception->moduleId);
            self::assertSame('explode', $exception->listenerId);
            self::assertSame(CoreStarted::class, $exception->eventClass);
            self::assertFalse($continued);
        }
    }

    public function testSealedDispatcherRejectsLateListeners(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->seal();

        $this->expectException(LogicException::class);
        $dispatcher->listen(CoreStarted::class, 'n3/late', 'late', static function (): void {
        });
    }

    public function testItRejectsDispatchDuringListenerRegistration(): void
    {
        $this->expectException(LogicException::class);

        (new EventDispatcher())->dispatch(new CoreStarted('0.2.0', new \DateTimeImmutable()));
    }
}
