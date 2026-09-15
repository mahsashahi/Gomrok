<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

use Gomrok\Modules\Payments\Domain\PaymentStatus;

/**
 * The result of {@see PaymentProviderPort::getPaymentStatus()} /
 * {@see SupportsManualPolling::pollPaymentStatus()}. `rawStatus` is always
 * preserved alongside `mappedStatus` — CLAUDE.md's "unknown provider statuses
 * must be stored safely" rule, honoured even when the mapping is confident.
 *
 * `subscriptionReference` (Phase 26) is the real provider Subscription
 * resource id — for Stripe, a `mode: subscription` checkout session's own
 * `subscription` field (mutually exclusive with `payment_intent`, which is
 * only present for `mode: payment` sessions). `null` for every provider that
 * doesn't create a real Subscription resource at checkout time (Mollie's
 * provisional subscription support has no analogous id yet — Phase 22 Q6).
 */
final readonly class ProviderPaymentStatus
{
    public function __construct(
        public string $providerReference,
        public string $rawStatus,
        public PaymentStatus $mappedStatus,
        public ?string $paymentIntentReference = null,
        public ?string $subscriptionReference = null,
    ) {
    }
}
