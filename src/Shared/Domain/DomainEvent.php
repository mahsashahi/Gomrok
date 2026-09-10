<?php

declare(strict_types=1);

namespace Gomrok\Shared\Domain;

use DateTimeImmutable;

/**
 * Marker for an in-process domain event (Architecture §5). Phase 6 defines
 * events but does not dispatch them — the synchronous dispatcher is added with
 * the first subscriber. Use cases currently return their event(s) in the result
 * so a caller *could* react.
 */
interface DomainEvent
{
    public function occurredAt(): DateTimeImmutable;
}
