<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Notifications\Domain;

use Gomrok\Modules\Notifications\Domain\NotifyWorthyStatuses;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 28 Q5 (user-specified): exactly these transitions are notify-worthy.
 */
final class NotifyWorthyStatusesTest extends TestCase
{
    #[Test]
    public function paymentNotifiesOnlyOnTheCuratedList(): void
    {
        $notifyWorthy = [
            PaymentStatus::Paid,
            PaymentStatus::Failed,
            PaymentStatus::Canceled,
            PaymentStatus::Expired,
            PaymentStatus::Refunded,
            PaymentStatus::PartiallyRefunded,
            PaymentStatus::Disputed,
            PaymentStatus::Chargeback,
        ];

        foreach (PaymentStatus::cases() as $status) {
            self::assertSame(
                \in_array($status, $notifyWorthy, true),
                NotifyWorthyStatuses::payment($status),
                "Unexpected notify-worthiness for payment status '{$status->value}'",
            );
        }
    }

    #[Test]
    public function paymentMidFlowStatusesAreNotNotifyWorthy(): void
    {
        self::assertFalse(NotifyWorthyStatuses::payment(PaymentStatus::Created));
        self::assertFalse(NotifyWorthyStatuses::payment(PaymentStatus::Pending));
        self::assertFalse(NotifyWorthyStatuses::payment(PaymentStatus::RequiresAction));
        self::assertFalse(NotifyWorthyStatuses::payment(PaymentStatus::Authorized));
    }

    #[Test]
    public function subscriptionNotifiesOnlyOnTheCuratedList(): void
    {
        $notifyWorthy = [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Cancelled];

        foreach (SubscriptionStatus::cases() as $status) {
            self::assertSame(
                \in_array($status, $notifyWorthy, true),
                NotifyWorthyStatuses::subscription($status),
                "Unexpected notify-worthiness for subscription status '{$status->value}'",
            );
        }
    }

    #[Test]
    public function subscriptionTrialingIsNotNotifyWorthy(): void
    {
        self::assertFalse(NotifyWorthyStatuses::subscription(SubscriptionStatus::Trialing));
    }
}
