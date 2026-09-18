<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Shared\Application\Events\DomainEventDispatcher;
use Gomrok\Shared\Domain\DomainEvent;

final class RecordingDomainEventDispatcher implements DomainEventDispatcher
{
    /** @var list<DomainEvent> */
    public array $events = [];

    public function dispatch(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<class-string<DomainEvent>>
     */
    public function eventClasses(): array
    {
        return array_map(static fn (DomainEvent $e): string => $e::class, $this->events);
    }
}
