<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain\Events;

use DateTimeImmutable;
use Gomrok\Shared\Domain\DomainEvent;

final readonly class ApiKeyIssued implements DomainEvent
{
    public function __construct(
        public int $clientId,
        public string $keyId,
        public string $prefix,
        private DateTimeImmutable $occurredAt,
    ) {
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
