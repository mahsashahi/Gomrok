<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure\Adapter\Mollie;

use Gomrok\Modules\Payments\Domain\PaymentStatus;

/**
 * Pure status-vocabulary translation — no SDK, no network, fully unit
 * testable, mirroring `StripeStatusMapper`. Mollie's payment vocabulary
 * (`open`/`pending`/`authorized`/`paid`/`failed`/`canceled`/`expired`) is a
 * single flat list, unlike Stripe's two-tier Checkout Session/PaymentIntent
 * split — one mapping method covers it.
 *
 * An unrecognised raw status never maps to a terminal or false-positive
 * status — it falls back to `Pending` (CLAUDE.md: "unknown provider statuses
 * must be stored safely and handled carefully"), same rationale as
 * `StripeStatusMapper`.
 */
final class MollieStatusMapper
{
    public function fromPaymentStatus(string $status): PaymentStatus
    {
        return match (strtolower(trim($status))) {
            'paid' => PaymentStatus::Paid,
            'authorized' => PaymentStatus::Authorized,
            'canceled' => PaymentStatus::Canceled,
            'expired' => PaymentStatus::Expired,
            'failed' => PaymentStatus::Failed,
            default => PaymentStatus::Pending, // open, pending, unrecognised
        };
    }

    /**
     * Provisional pass-through — see {@see \Gomrok\Modules\Providers\Application\Adapter\ProviderSubscriptionStatus}.
     * `MollieAdapter::createSubscription()` (Phase 22 Q6) only ever produces a
     * first-payment reference this phase, so this reuses the payment status
     * vocabulary rather than a real Mollie subscription status.
     */
    public function fromSubscription(string $status): string
    {
        return strtolower(trim($status));
    }
}
