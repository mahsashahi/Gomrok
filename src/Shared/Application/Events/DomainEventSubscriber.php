<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Events;

use Gomrok\Shared\Domain\DomainEvent;

/**
 * A module's reaction to another module's event (Architecture.md §5 — "a
 * module that needs another module's behaviour depends on an interface").
 * Registered with the dispatcher via DI; never called directly by the
 * module that raises the event.
 */
interface DomainEventSubscriber
{
    /**
     * @return list<class-string<DomainEvent>>
     */
    public function subscribesTo(): array;

    public function handle(DomainEvent $event): void;
}
