<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Dashboard;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Dashboard\HomeDashboardHandler;
use Gomrok\Modules\Admin\Application\PaymentProviderResolver;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionStatus;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryPaymentDirectory;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryProviderRoutingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemorySubscriptionDirectory;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HomeDashboardHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;

    private DateTimeImmutable $now;
    private InMemoryClientDirectory $clients;
    private InMemoryPaymentRepository $paymentsRepo;
    private InMemorySubscriptionRepository $subscriptionsRepo;
    private HomeDashboardHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-15T15:00:00+00:00');
        $this->clients = new InMemoryClientDirectory();
        $this->clients->add(new ClientSnapshot(self::CLIENT, 'televika', 'Televika', ClientStatus::Active, 'EUR', 'DE', 'UTC'));

        $this->paymentsRepo = new InMemoryPaymentRepository();
        $this->subscriptionsRepo = new InMemorySubscriptionRepository();

        $this->handler = new HomeDashboardHandler(
            $this->clients,
            new InMemoryPaymentDirectory($this->paymentsRepo),
            new InMemorySubscriptionDirectory($this->subscriptionsRepo),
            new PaymentProviderResolver(new InMemoryProviderRoutingDecisionSnapshotRepository(), new StubProviderAccountDirectory()),
            new FrozenClock('2026-09-15T15:00:00+00:00'),
        );
    }

    #[Test]
    public function countsTodaysPaymentsByStatus(): void
    {
        $this->seedPayment(PaymentStatus::Paid, $this->now);
        $this->seedPayment(PaymentStatus::Paid, $this->now->modify('-1 hour'));
        $this->seedPayment(PaymentStatus::Failed, $this->now);
        $this->seedPayment(PaymentStatus::Paid, $this->now->modify('-2 days')); // not today

        $result = $this->handler->forClient(self::CLIENT, 'today');

        self::assertSame(2, $result->successfulPaymentsToday);
        self::assertSame(1, $result->failedPaymentsToday);
    }

    #[Test]
    public function countsActiveAndTrialingSubscriptionsButNotCancelled(): void
    {
        $this->seedSubscription($this->now);
        $active = $this->subscriptionsRepo->findById(1);
        self::assertNotNull($active);

        $this->seedSubscription($this->now);
        $toCancel = $this->subscriptionsRepo->findById(2);
        self::assertNotNull($toCancel);
        $toCancel->transitionTo(SubscriptionStatus::Cancelled, $this->now);
        $this->subscriptionsRepo->save($toCancel);

        $result = $this->handler->forClient(self::CLIENT, 'today');

        self::assertSame(1, $result->activeSubscriptions);
    }

    #[Test]
    public function sparklineHasTwentyFourHourlyBuckets(): void
    {
        $this->seedPayment(PaymentStatus::Paid, $this->now);

        $result = $this->handler->forClient(self::CLIENT, 'today');

        self::assertCount(24, $result->sparkline);
    }

    #[Test]
    public function overviewStatsCompareTheCurrentPeriodAgainstThePreviousOne(): void
    {
        // 7-day period: [now-6d, now]. Previous period: [now-13d, now-6d).
        $this->seedPayment(PaymentStatus::Paid, $this->now); // current period
        $this->seedPayment(PaymentStatus::Paid, $this->now->modify('-8 days')); // previous period

        $result = $this->handler->forClient(self::CLIENT, '7d');

        $successfulStat = $result->overviewStats[1];
        self::assertSame('Successful payments', $successfulStat->label);
        self::assertSame('1', $successfulStat->value);
        self::assertSame('+0%', $successfulStat->deltaLabel);
        self::assertTrue($successfulStat->deltaIsPositive);
    }

    #[Test]
    public function recentPaymentsAreSortedNewestFirstAndLimitedToFive(): void
    {
        for ($i = 0; $i < 7; ++$i) {
            $this->seedPayment(PaymentStatus::Paid, $this->now->modify("-{$i} hours"));
        }

        $result = $this->handler->forClient(self::CLIENT, 'today');

        self::assertCount(5, $result->recentPayments);
        // Newest (0 hours ago) should be first.
        self::assertSame('2026-09-15 15:00', $result->recentPayments[0]->createdAt);
    }

    #[Test]
    public function anUnknownClientDefaultsToUsdAndReturnsAnEmptyDashboard(): void
    {
        $result = $this->handler->forClient(999, 'today');

        self::assertSame(0, $result->successfulPaymentsToday);
        self::assertCount(0, $result->recentPayments);
    }

    private function seedPayment(PaymentStatus $status, DateTimeImmutable $createdAt): void
    {
        $payment = Payment::create(self::CLIENT, null, 'user-1', self::PACKAGE, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $createdAt);
        if ($status !== PaymentStatus::Created) {
            $payment->transitionTo(PaymentStatus::Pending, $createdAt);
        }
        if ($status !== PaymentStatus::Created && $status !== PaymentStatus::Pending) {
            $payment->transitionTo($status, $createdAt);
        }
        $this->paymentsRepo->save($payment);
    }

    private function seedSubscription(DateTimeImmutable $createdAt): void
    {
        $subscription = Subscription::create(self::CLIENT, 'user-1', 100, self::PACKAGE, 1, 'EUR', 2900, null, SubscriptionInterval::Monthly, false, null, $createdAt);
        $this->subscriptionsRepo->save($subscription);
    }
}
