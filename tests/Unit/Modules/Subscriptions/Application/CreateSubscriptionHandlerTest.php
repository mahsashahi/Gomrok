<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Subscriptions\Application;

use DateTimeImmutable;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPayableAmount;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Packages\Application\PackagePurchaseCapabilityResolver;
use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageCode;
use Gomrok\Modules\Packages\Domain\PackagePurchaseCapability;
use Gomrok\Modules\Pricing\Application\PriceSource;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshot;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Application\CreateSubscription\CreateSubscriptionCommand;
use Gomrok\Modules\Subscriptions\Application\CreateSubscription\CreateSubscriptionHandler;
use Gomrok\Modules\Subscriptions\Application\CreateSubscription\CreateSubscriptionResult;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionStatus;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPackageRepository;
use Gomrok\Tests\Support\InMemoryPricingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryProviderRoutingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemorySubscriptionEventRepository;
use Gomrok\Tests\Support\InMemorySubscriptionPaymentLinkRepository;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use Gomrok\Tests\Support\InMemoryVoucherDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryVoucherRedemptionRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateSubscriptionHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    private DateTimeImmutable $now;
    private InMemoryCheckoutAttemptRepository $attempts;
    private InMemoryProviderRoutingDecisionSnapshotRepository $routingSnapshots;
    private InMemoryPricingDecisionSnapshotRepository $pricingSnapshots;
    private InMemoryPackageRepository $packages;
    private InMemorySubscriptionRepository $subscriptions;
    private InMemorySubscriptionEventRepository $subscriptionEvents;
    private InMemorySubscriptionPaymentLinkRepository $paymentLinks;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private CreateSubscriptionHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $this->attempts = new InMemoryCheckoutAttemptRepository();
        $this->routingSnapshots = new InMemoryProviderRoutingDecisionSnapshotRepository();
        $this->pricingSnapshots = new InMemoryPricingDecisionSnapshotRepository();
        $this->packages = new InMemoryPackageRepository();
        $this->subscriptions = new InMemorySubscriptionRepository();
        $this->subscriptionEvents = new InMemorySubscriptionEventRepository();
        $this->paymentLinks = new InMemorySubscriptionPaymentLinkRepository();
        $this->gatewayReferences = new InMemoryGatewayReferenceRepository();

        $this->handler = new CreateSubscriptionHandler(
            $this->attempts,
            $this->routingSnapshots,
            new ResolveCheckoutPayableAmount($this->pricingSnapshots, new InMemoryVoucherDecisionSnapshotRepository(), new InMemoryVoucherRedemptionRepository()),
            new PackagePurchaseCapabilityResolver($this->packages),
            $this->subscriptions,
            $this->subscriptionEvents,
            $this->paymentLinks,
            $this->gatewayReferences,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
        );

        $this->seedPackage(false, null);
    }

    #[Test]
    public function createsASubscriptionLinkedToItsFirstPayment(): void
    {
        $attemptId = $this->seedAttempt();

        $result = $this->handler->handle(new CreateSubscriptionCommand(self::CLIENT, $attemptId, 501, 'sub_test_1'));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof CreateSubscriptionResult);
        self::assertSame('active', $value->status);

        $subscription = $this->subscriptions->findById($value->subscriptionId);
        self::assertNotNull($subscription);
        self::assertSame(self::CLIENT, $subscription->clientId());
        self::assertSame('user-1', $subscription->clientUserRef());
        self::assertSame($attemptId, $subscription->checkoutAttemptId());
        self::assertSame(2900, $subscription->amountMinor());
        self::assertSame(SubscriptionStatus::Active, $subscription->status());

        $link = $this->paymentLinks->findByPaymentId(501);
        self::assertNotNull($link);
        self::assertSame($value->subscriptionId, $link->subscriptionId);

        $events = $this->subscriptionEvents->forSubscription($value->subscriptionId);
        self::assertCount(1, $events);
        self::assertSame('created', $events[0]->kind);

        $references = $this->gatewayReferences->forSubscription($value->subscriptionId);
        self::assertCount(1, $references);
        self::assertSame('sub_test_1', $references[0]->referenceValue);
    }

    #[Test]
    public function startsInTrialWhenThePackageHasATrial(): void
    {
        $this->seedPackage(true, 14);
        $attemptId = $this->seedAttempt();

        $result = $this->handler->handle(new CreateSubscriptionCommand(self::CLIENT, $attemptId, 501));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof CreateSubscriptionResult);
        self::assertSame('trialing', $value->status);

        $subscription = $this->subscriptions->findById($value->subscriptionId);
        self::assertNotNull($subscription);
        self::assertNotNull($subscription->trialEndsAt());
    }

    #[Test]
    public function isIdempotentForAnAttemptThatAlreadyHasASubscription(): void
    {
        $attemptId = $this->seedAttempt();

        $first = $this->handler->handle(new CreateSubscriptionCommand(self::CLIENT, $attemptId, 501));
        $second = $this->handler->handle(new CreateSubscriptionCommand(self::CLIENT, $attemptId, 502));

        self::assertTrue($first->isOk());
        self::assertTrue($second->isOk());
        $firstValue = $first->value();
        $secondValue = $second->value();
        \assert($firstValue instanceof CreateSubscriptionResult);
        \assert($secondValue instanceof CreateSubscriptionResult);
        self::assertSame($firstValue->subscriptionId, $secondValue->subscriptionId);

        // The retry with a different payment id must not create a second link.
        self::assertNull($this->paymentLinks->findByPaymentId(502));
    }

    #[Test]
    public function rejectsAnAttemptWithNoSubscriptionInterval(): void
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-2', self::PACKAGE, 'DE', 'EUR', PurchaseType::OneTimePayment, null, null, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);
        $this->seedPricing($attemptId);
        $this->seedRouting($attemptId);

        $result = $this->handler->handle(new CreateSubscriptionCommand(self::CLIENT, $attemptId, 501));

        self::assertTrue($result->isErr());
        self::assertSame('subscription.interval_missing', $result->error()->code);
    }

    #[Test]
    public function rejectsAnAttemptWithNoClientUserRef(): void
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, null, 'order-3', self::PACKAGE, 'DE', 'EUR', PurchaseType::Subscription, null, SubscriptionInterval::Monthly, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);
        $this->seedPricing($attemptId);
        $this->seedRouting($attemptId);

        $result = $this->handler->handle(new CreateSubscriptionCommand(self::CLIENT, $attemptId, 501));

        self::assertTrue($result->isErr());
        self::assertSame('subscription.client_user_ref_required', $result->error()->code);
    }

    #[Test]
    public function anUnknownCheckoutAttemptIsNotFound(): void
    {
        $result = $this->handler->handle(new CreateSubscriptionCommand(self::CLIENT, 999, 501));

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.not_found', $result->error()->code);
    }

    #[Test]
    public function aRealAttemptOwnedByAnotherClientIsNotFound(): void
    {
        $attemptId = $this->seedAttempt();

        $result = $this->handler->handle(new CreateSubscriptionCommand(self::CLIENT + 1, $attemptId, 501));

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.not_found', $result->error()->code);
    }

    private function seedAttempt(): int
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::Subscription, null, SubscriptionInterval::Monthly, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);

        $this->seedPricing($attemptId);
        $this->seedRouting($attemptId);

        return $attemptId;
    }

    private function seedPricing(int $attemptId): void
    {
        $price = new ResolvedPrice(self::PACKAGE, 'pro', 2900, '29.00', 'EUR', PriceSource::Baseline, 'default', true, 'Pro', null, false, 0);
        $this->pricingSnapshots->save(PricingDecisionSnapshot::of($attemptId, self::CLIENT, $price, $this->now));
    }

    private function seedRouting(int $attemptId): void
    {
        $this->routingSnapshots->save(new ProviderRoutingDecisionSnapshot(null, $attemptId, self::CLIENT, self::PROVIDER_ACCOUNT, 'card', 'subscription', [], $this->now));
    }

    private function seedPackage(bool $hasTrial, ?int $trialDays): void
    {
        $package = Package::create(self::CLIENT, PackageCode::of('pro'), 'Pro', null, null, $this->now);
        $package->assignId(self::PACKAGE);
        $package->setPurchaseCapabilities([
            PackagePurchaseCapability::of(PurchaseType::Subscription, $hasTrial, $trialDays),
        ], $this->now);
        $this->packages->save($package);
    }
}
