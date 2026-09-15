<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Domain;

use DateTimeImmutable;

/**
 * Ties one {@see \Gomrok\Modules\Payments\Domain\Payment} to the subscription
 * it was charged under (Phase 26 Q2) — including renewal charges, which have
 * no `checkout_attempts` row of their own. `UNIQUE (payment_id)`: a payment
 * belongs to at most one subscription.
 */
final readonly class SubscriptionPaymentLink
{
    public function __construct(
        public ?int $id,
        public int $subscriptionId,
        public int $paymentId,
        public ?DateTimeImmutable $billingPeriodStart,
        public ?DateTimeImmutable $billingPeriodEnd,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public static function link(int $subscriptionId, int $paymentId, ?DateTimeImmutable $billingPeriodStart, ?DateTimeImmutable $billingPeriodEnd, DateTimeImmutable $now): self
    {
        return new self(null, $subscriptionId, $paymentId, $billingPeriodStart, $billingPeriodEnd, $now);
    }
}
