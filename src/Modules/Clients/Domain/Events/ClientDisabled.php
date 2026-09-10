<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain\Events;

use DateTimeImmutable;
use Gomrok\Shared\Domain\DomainEvent;

final readonly class ClientDisabled implements DomainEvent
{
    public function __construct(
        public int $clientId,
        public string $slug,
        public ?string $reason,
        private DateTimeImmutable $occurredAt,
    ) {
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
