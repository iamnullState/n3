<?php

declare(strict_types=1);

namespace N3\Core\Event;

use Closure;
use LogicException;
use Throwable;

final class EventDispatcher implements EventListenerRegistry
{
    /** @var array<class-string, list<array{module: string, listener: string, priority: int, order: int, callback: Closure(object): void}>> */
    private array $listeners = [];

    private int $registrationOrder = 0;

    private bool $sealed = false;

    /** @param class-string $eventClass */
    public function listen(
        string $eventClass,
        string $moduleId,
        string $listenerId,
        callable $listener,
        int $priority = 0,
    ): void {
        if ($this->sealed) {
            throw new LogicException('Event listeners cannot be registered after module boot has completed.');
        }

        if (!class_exists($eventClass) && !interface_exists($eventClass)) {
            throw new LogicException(sprintf('Event type "%s" does not exist.', $eventClass));
        }

        if (trim($moduleId) === '' || trim($listenerId) === '') {
            throw new LogicException('Event listener module and listener identifiers cannot be empty.');
        }

        foreach ($this->listeners[$eventClass] ?? [] as $registered) {
            if ($registered['module'] === $moduleId && $registered['listener'] === $listenerId) {
                throw new LogicException(sprintf(
                    'Listener "%s" is already registered for module "%s" and event "%s".',
                    $listenerId,
                    $moduleId,
                    $eventClass,
                ));
            }
        }

        $this->listeners[$eventClass][] = [
            'module' => $moduleId,
            'listener' => $listenerId,
            'priority' => $priority,
            'order' => $this->registrationOrder++,
            'callback' => Closure::fromCallable($listener),
        ];
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    public function dispatch(object $event): void
    {
        if (!$this->sealed) {
            throw new LogicException('Events cannot be dispatched until module boot has completed.');
        }

        $listeners = [];

        foreach ($this->listeners as $eventClass => $registered) {
            if ($event instanceof $eventClass) {
                array_push($listeners, ...$registered);
            }
        }

        usort($listeners, static fn (array $left, array $right): int =>
            ($right['priority'] <=> $left['priority']) ?: ($left['order'] <=> $right['order'])
        );

        foreach ($listeners as $listener) {
            try {
                ($listener['callback'])($event);
            } catch (Throwable $exception) {
                throw new EventListenerFailed(
                    moduleId: $listener['module'],
                    listenerId: $listener['listener'],
                    eventClass: $event::class,
                    previous: $exception,
                );
            }
        }
    }
}
