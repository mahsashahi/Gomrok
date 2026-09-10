<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain\Events;

use DateTimeImmutable;
use Gomrok\Shared\Domain\DomainEvent;

final readonly class ClientUpdated implements DomainEvent
{
    /**
     * @param list<string> $changed names of the fields that changed
     */
    public function __construct(
        public int $clientId,
        public string $slug,
        public array $changed,
        private DateTimeImmutable $occurredAt,
    ) {
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
