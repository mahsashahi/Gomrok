<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure\Adapter\Stripe;

use Gomrok\Modules\Payments\Domain\PaymentStatus;

/**
 * Pure status-vocabulary translation — no SDK, no network, fully unit
 * testable (this phase's exit criterion). Stripe has two overlapping but
 * distinct status vocabularies for a hosted payment: the Checkout Session's
 * own `status`/`payment_status`, and the underlying PaymentIntent's `status`
 * once one exists. Both funnel into the same {@see PaymentStatus}.
 *
 * An unrecognised raw status never maps to a terminal or false-positive
 * status — it falls back to `Pending` (CLAUDE.md: "unknown provider statuses
 * must be stored safely and handled carefully"; the raw string is always
 * preserved alongside the mapped one, see {@see \Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentStatus}).
 * `Pending` is the safe default: it asserts nothing false ("paid", "failed",
 * "refunded" would all be claims this mapper cannot back up for a string it
 * doesn't recognise), and it keeps the payment open for a human or a later
 * webhook to resolve, rather than closing it out on a guess.
 */
final class StripeStatusMapper
{
    public function fromCheckoutSession(string $sessionStatus, ?string $paymentStatus): PaymentStatus
    {
        $sessionStatus = strtolower(trim($sessionStatus));
        $paymentStatus = $paymentStatus !== null ? strtolower(trim($paymentStatus)) : null;

        return match (true) {
            $sessionStatus === 'expired' => PaymentStatus::Expired,
            $sessionStatus === 'complete' && $paymentStatus === 'paid' => PaymentStatus::Paid,
            $sessionStatus === 'complete' && $paymentStatus === 'no_payment_required' => PaymentStatus::Paid,
            default => PaymentStatus::Pending,
        };
    }

    public function fromPaymentIntent(string $paymentIntentStatus): PaymentStatus
    {
        return match (strtolower(trim($paymentIntentStatus))) {
            'requires_action' => PaymentStatus::RequiresAction,
            'requires_capture' => PaymentStatus::Authorized,
            'succeeded' => PaymentStatus::Paid,
            'canceled' => PaymentStatus::Canceled,
            default => PaymentStatus::Pending, // requires_payment_method, requires_confirmation, processing, unrecognised
        };
    }

    /**
     * Provisional pass-through — see {@see \Gomrok\Modules\Providers\Application\Adapter\ProviderSubscriptionStatus}.
     */
    public function fromSubscription(string $subscriptionStatus): string
    {
        return strtolower(trim($subscriptionStatus));
    }
}
