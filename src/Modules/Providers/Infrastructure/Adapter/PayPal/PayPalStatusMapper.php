<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure\Adapter\PayPal;

use Gomrok\Modules\Payments\Domain\PaymentStatus;

/**
 * Pure status-vocabulary translation — no HTTP, no network, fully unit
 * testable, mirroring `StripeStatusMapper`/`MollieStatusMapper`. Unlike
 * Stripe's Checkout Session/PaymentIntent split (two named methods) or
 * Mollie's single flat vocabulary, PayPal spreads its statuses across three
 * resource types (Order, Authorization, Capture) that all surface through the
 * same generic `mapProviderStatusToInternalStatus(string $providerStatus)`
 * call — the port gives no hint which resource a raw status came from — so
 * this is one shared table covering the union of all three vocabularies
 * rather than three separate methods with no way to pick between them.
 *
 * An unrecognised raw status never maps to a terminal or false-positive
 * status — it falls back to `Pending`, same rationale as the other mappers
 * (CLAUDE.md: "unknown provider statuses must be stored safely").
 */
final class PayPalStatusMapper
{
    public function fromStatus(string $status): PaymentStatus
    {
        return match (strtoupper(trim($status))) {
            // Order
            'PAYER_ACTION_REQUIRED' => PaymentStatus::RequiresAction,
            'VOIDED' => PaymentStatus::Canceled,
            'COMPLETED' => PaymentStatus::Paid,
            // Authorization
            'CAPTURED' => PaymentStatus::Paid,
            'DENIED' => PaymentStatus::Failed,
            'EXPIRED' => PaymentStatus::Expired,
            'PARTIALLY_CAPTURED' => PaymentStatus::Authorized,
            // Capture
            'DECLINED' => PaymentStatus::Failed,
            'PARTIALLY_REFUNDED' => PaymentStatus::PartiallyRefunded,
            'REFUNDED' => PaymentStatus::Refunded,
            'FAILED' => PaymentStatus::Failed,
            default => PaymentStatus::Pending, // CREATED, SAVED, APPROVED, PENDING, unrecognised
        };
    }
}
