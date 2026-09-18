<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Domain\Events;

use DateTimeImmutable;
use Gomrok\Shared\Domain\DomainEvent;

/**
 * Raised once a {@see \Gomrok\Modules\Subscriptions\Domain\Subscription}
 * genuinely changes status (never for a same-status no-op). `providerAccountId`
 * mirrors {@see \Gomrok\Modules\Payments\Domain\Events\PaymentStatusChanged} —
 * carried for the Notifications module's per-account callback-URL override
 * (Phase 28 Q1).
 */
final readonly class SubscriptionStatusChanged implements DomainEvent
{
    public function __construct(
        public int $subscriptionId,
        public int $clientId,
        public string $fromStatus,
        public string $toStatus,
        public ?int $providerAccountId,
        private DateTimeImmutable $occurredAtValue,
    ) {
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAtValue;
    }
}
