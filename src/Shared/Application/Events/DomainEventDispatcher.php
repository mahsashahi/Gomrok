<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Events;

use Gomrok\Shared\Domain\DomainEvent;

/**
 * The in-process synchronous domain-event dispatcher (Architecture.md §5).
 * Callers dispatch **after** the triggering transaction commits, never
 * from inside it — a subscriber that fails must not roll back the change
 * that already happened.
 */
interface DomainEventDispatcher
{
    public function dispatch(DomainEvent $event): void;
}
