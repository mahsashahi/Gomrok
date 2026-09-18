<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Reconciliation\Application\Jobs;

use DateTimeImmutable;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Application\ProviderCapabilityResolver;
use Gomrok\Modules\Providers\Application\Adapter\ProviderSubscriptionStatus;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Reconciliation\Application\Jobs\SubscriptionReconciliationScanHandler;
use Gomrok\Modules\Reconciliation\Application\ReconciliationFindingFilter;
use Gomrok\Modules\Subscriptions\Application\ResolveSubscriptionActionContext;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use Gomrok\Tests\Support\InMemoryReconciliationFindingRepository;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SubscriptionReconciliationScanHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    private DateTimeImmutable $now;
    private InMemoryCheckoutAttemptRepository $attempts;
    private InMemorySubscriptionRepository $subscriptions;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private InMemoryReconciliationFindingRepository $findings;
    private FakePaymentProviderPort $adapter;
    private SubscriptionReconciliationScanHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $this->attempts = new InMemoryCheckoutAttemptRepository();
        $this->subscriptions = new InMemorySubscriptionRepository();
        $this->gatewayReferences = new InMemoryGatewayReferenceRepository();
        $this->findings = new InMemoryReconciliationFindingRepository();
        $this->adapter = new FakePaymentProviderPort();

        $accounts = (new StubProviderAccountDirectory())->add(self::PROVIDER_ACCOUNT, self::CLIENT, 'account-main', 'stripe');
        $context = new ResolveSubscriptionActionContext(
            $accounts,
            new ProviderCapabilityResolver(InMemoryProviderTypeDeclarations::withKnownProviders()),
            (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $this->adapter),
            $this->gatewayReferences,
        );

        $this->handler = new SubscriptionReconciliationScanHandler(
            $this->subscriptions,
            $context,
            $this->findings,
            new FrozenClock('2026-09-17T12:00:00+00:00'),
        );
    }

    private function seedSubscription(): int
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::Subscription, null, SubscriptionInterval::Monthly, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);

        $subscription = Subscription::create(self::CLIENT, 'user-1', $attemptId, self::PACKAGE, self::PROVIDER_ACCOUNT, 'EUR', 2900, null, SubscriptionInterval::Monthly, false, null, $this->now);
        $this->subscriptions->save($subscription);
        $subscriptionId = $subscription->id();
        \assert($subscriptionId !== null);

        $this->gatewayReferences->save(GatewayReference::forSubscription(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::Subscription, 'sub_test_1', $subscriptionId, $this->now));

        return $subscriptionId;
    }

    #[Test]
    public function exposesItsTypeAndRecurrence(): void
    {
        self::assertSame('subscription_reconciliation_scan', $this->handler->type());
        self::assertSame(30, $this->handler->recurrenceIntervalMinutes());
    }

    #[Test]
    public function recordsAFindingWhenTheProviderStatusDisagreesWithTheLocalStatus(): void
    {
        $subscriptionId = $this->seedSubscription();
        $this->adapter->subscriptionStatusResult(new ProviderSubscriptionStatus('sub_test_1', 'canceled', 'cancelled'));

        $job = Job::schedule('subscription_reconciliation_scan', null, $this->now, $this->now);
        $result = $this->handler->handle($job);

        self::assertTrue($result->success);
        self::assertSame(['scanned' => 1, 'drifted' => 1, 'skipped' => 0], $result->summary);

        $findings = $this->findings->search(new ReconciliationFindingFilter(clientId: self::CLIENT, resolution: 'all'));
        self::assertCount(1, $findings);
        self::assertSame($subscriptionId, $findings[0]->targetId());
        self::assertSame('active', $findings[0]->localStatus());
        self::assertSame('cancelled', $findings[0]->mappedProviderStatus());
    }

    #[Test]
    public function recordsNoFindingWhenTheProviderStatusAgrees(): void
    {
        $this->seedSubscription();
        $this->adapter->subscriptionStatusResult(new ProviderSubscriptionStatus('sub_test_1', 'active', 'active'));

        $job = Job::schedule('subscription_reconciliation_scan', null, $this->now, $this->now);
        $result = $this->handler->handle($job);

        self::assertSame(['scanned' => 1, 'drifted' => 0, 'skipped' => 0], $result->summary);
    }

    #[Test]
    public function skipsASubscriptionWithNoResolvableReference(): void
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-2', self::PACKAGE, 'DE', 'EUR', PurchaseType::Subscription, null, SubscriptionInterval::Monthly, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);
        $subscription = Subscription::create(self::CLIENT, 'user-1', $attemptId, self::PACKAGE, self::PROVIDER_ACCOUNT, 'EUR', 2900, null, SubscriptionInterval::Monthly, false, null, $this->now);
        $this->subscriptions->save($subscription);

        $job = Job::schedule('subscription_reconciliation_scan', null, $this->now, $this->now);
        $result = $this->handler->handle($job);

        self::assertSame(['scanned' => 1, 'drifted' => 0, 'skipped' => 1], $result->summary);
    }
}
