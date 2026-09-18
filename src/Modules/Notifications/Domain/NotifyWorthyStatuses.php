<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Domain;

use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionStatus;

/**
 * The curated, centralized list of which status transitions are worth
 * telling a client about (Phase 28 Q5, user-specified — overriding the
 * simpler "notify on everything" default that was recommended). Kept as one
 * explicit place per the user's own instruction, so a future status can be
 * added to (or removed from) either list without hunting through the four
 * call sites that raise the underlying events.
 *
 * Payments: `paid`/`failed`/`canceled`/`expired`/`refunded`/
 * `partially_refunded`/`disputed`/`chargeback`. Not `created`/`pending`/
 * `requires_action`/`authorized` — pure mid-flow states.
 *
 * Subscriptions: `active`/`past_due`/`cancelled`. Not `trialing`.
 */
final class NotifyWorthyStatuses
{
    private const PAYMENT = [
        PaymentStatus::Paid,
        PaymentStatus::Failed,
        PaymentStatus::Canceled,
        PaymentStatus::Expired,
        PaymentStatus::Refunded,
        PaymentStatus::PartiallyRefunded,
        PaymentStatus::Disputed,
        PaymentStatus::Chargeback,
    ];

    private const SUBSCRIPTION = [
        SubscriptionStatus::Active,
        SubscriptionStatus::PastDue,
        SubscriptionStatus::Cancelled,
    ];

    public static function payment(PaymentStatus $status): bool
    {
        return \in_array($status, self::PAYMENT, true);
    }

    public static function subscription(SubscriptionStatus $status): bool
    {
        return \in_array($status, self::SUBSCRIPTION, true);
    }
}
