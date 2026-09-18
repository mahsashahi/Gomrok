<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain\Events;

use DateTimeImmutable;
use Gomrok\Shared\Domain\DomainEvent;

/**
 * Raised once a {@see \Gomrok\Modules\Payments\Domain\Payment} genuinely
 * changes status (never for a same-status no-op — the caller only raises
 * this after confirming `fromStatus !== toStatus`). `providerAccountId` is
 * carried for the Notifications module's per-account callback-URL override
 * (Phase 28 Q1); it is whichever account produced *this* transition, not
 * necessarily the payment's original account.
 */
final readonly class PaymentStatusChanged implements DomainEvent
{
    public function __construct(
        public int $paymentId,
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
