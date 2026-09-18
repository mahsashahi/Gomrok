<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Checkout\Application;

use DateTimeImmutable;
use Gomrok\Modules\Checkout\Application\ReconcileCheckoutStatus\ReconcileCheckoutStatusHandler;
use Gomrok\Modules\Checkout\Application\ReconcileCheckoutStatus\ReconcileCheckoutStatusResult;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPayableAmount;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Packages\Application\PackagePurchaseCapabilityResolver;
use Gomrok\Modules\Payments\Application\ChangePaymentStatus\ChangePaymentStatusHandler;
use Gomrok\Modules\Payments\Application\CreatePayment\CreatePaymentHandler;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Pricing\Application\PriceSource;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshot;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Application\CreateSubscription\CreateSubscriptionHandler;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPackageRepository;
use Gomrok\Tests\Support\InMemoryPaymentAttemptRepository;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryPricingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryProviderRoutingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemorySubscriptionEventRepository;
use Gomrok\Tests\Support\InMemorySubscriptionPaymentLinkRepository;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use Gomrok\Tests\Support\InMemoryVoucherDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryVoucherRedemptionRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\RecordingDomainEventDispatcher;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReconcileCheckoutStatusHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    private DateTimeImmutable $now;
    private InMemoryCheckoutAttemptRepository $attempts;
    private InMemoryProviderRoutingDecisionSnapshotRepository $routingSnapshots;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private InMemoryPaymentRepository $payments;
    private InMemoryPricingDecisionSnapshotRepository $pricingSnapshots;
    private StubProviderAdapterFactory $adapterFactory;
    private FakePaymentProviderPort $adapter;
    private ReconcileCheckoutStatusHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');
        $this->attempts = new InMemoryCheckoutAttemptRepository();
        $this->routingSnapshots = new InMemoryProviderRoutingDecisionSnapshotRepository();
        $this->gatewayReferences = new InMemoryGatewayReferenceRepository();
        $this->payments = new InMemoryPaymentRepository();
        $this->pricingSnapshots = new InMemoryPricingDecisionSnapshotRepository();
        $this->adapter = new FakePaymentProviderPort();
        $this->adapterFactory = (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $this->adapter);

        $createPayment = new CreatePaymentHandler(
            $this->attempts,
            $this->payments,
            new ResolveCheckoutPayableAmount($this->pricingSnapshots, new InMemoryVoucherDecisionSnapshotRepository(), new InMemoryVoucherRedemptionRepository()),
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-13T12:00:00+00:00'),
        );

        $changePaymentStatus = new ChangePaymentStatusHandler(
            $this->payments,
            new InMemoryPaymentAttemptRepository(),
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-13T12:00:00+00:00'),
            new RecordingDomainEventDispatcher(),
        );

        $createSubscription = new CreateSubscriptionHandler(
            $this->attempts,
            $this->routingSnapshots,
            new ResolveCheckoutPayableAmount($this->pricingSnapshots, new InMemoryVoucherDecisionSnapshotRepository(), new InMemoryVoucherRedemptionRepository()),
            new PackagePurchaseCapabilityResolver(new InMemoryPackageRepository()),
            new InMemorySubscriptionRepository(),
            new InMemorySubscriptionEventRepository(),
            new InMemorySubscriptionPaymentLinkRepository(),
            $this->gatewayReferences,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-13T12:00:00+00:00'),
        );

        $this->handler = new ReconcileCheckoutStatusHandler(
            $this->attempts,
            $this->routingSnapshots,
            $this->gatewayReferences,
            $this->adapterFactory,
            $createPayment,
            $changePaymentStatus,
            $createSubscription,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-13T12:00:00+00:00'),
        );
    }

    #[Test]
    public function aPaidProviderStatusConfirmsAndCreatesThePayment(): void
    {
        $attemptId = $this->providerCheckoutCreatedAttempt();
        $this->adapter->createPaymentResultStatus('paid');

        $result = $this->handler->handle($attemptId);

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof ReconcileCheckoutStatusResult);
        self::assertSame('converted_to_payment', $value->status);
        self::assertTrue($value->paid);

        $payment = $this->payments->findByCheckoutAttemptId($attemptId);
        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Paid, $payment->status());
    }

    #[Test]
    public function aCanceledProviderStatusExitsWithoutCreatingAPayment(): void
    {
        $attemptId = $this->providerCheckoutCreatedAttempt();
        $this->adapter->createPaymentResultStatus('canceled');

        $result = $this->handler->handle($attemptId);

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof ReconcileCheckoutStatusResult);
        self::assertSame('canceled', $value->status);
        self::assertFalse($value->paid);
        self::assertNull($this->payments->findByCheckoutAttemptId($attemptId));
    }

    #[Test]
    public function aStillPendingProviderStatusStaysAtReturnedFromProvider(): void
    {
        $attemptId = $this->providerCheckoutCreatedAttempt();
        $this->adapter->createPaymentResultStatus('open');

        $result = $this->handler->handle($attemptId);

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof ReconcileCheckoutStatusResult);
        self::assertSame('returned_from_provider', $value->status);
        self::assertFalse($value->paid);
    }

    #[Test]
    public function anAlreadyTerminalAttemptIsReportedWithoutASecondProviderCall(): void
    {
        $attemptId = $this->providerCheckoutCreatedAttempt();
        $this->adapter->createPaymentResultStatus('paid');
        $this->handler->handle($attemptId);

        $result = $this->handler->handle($attemptId);

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof ReconcileCheckoutStatusResult);
        self::assertSame('converted_to_payment', $value->status);
        self::assertCount(1, $this->payments->forClient(self::CLIENT));
    }

    #[Test]
    public function anUnknownAttemptIsNotFound(): void
    {
        $result = $this->handler->handle(999);

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.not_found', $result->error()->code);
    }

    private function providerCheckoutCreatedAttempt(): int
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::OneTimePayment, null, null, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);
        $attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $this->now);
        $attempt->transitionTo(CheckoutAttemptStatus::ProviderSelected, $this->now);
        $attempt->transitionTo(CheckoutAttemptStatus::ProviderCheckoutCreated, $this->now);
        $this->attempts->save($attempt);

        $this->routingSnapshots->save(new ProviderRoutingDecisionSnapshot(null, $attemptId, self::CLIENT, self::PROVIDER_ACCOUNT, 'card', 'one_time_payment', [], $this->now));
        $this->gatewayReferences->save(GatewayReference::forCheckoutAttempt(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::CheckoutSession, 'cs_test_1', $attemptId, $this->now));

        $price = new ResolvedPrice(self::PACKAGE, 'pro', 2900, '29.00', 'EUR', PriceSource::Baseline, 'default', true, 'Pro', null, false, 0);
        $this->pricingSnapshots->save(PricingDecisionSnapshot::of($attemptId, self::CLIENT, $price, $this->now));

        return $attemptId;
    }
}
