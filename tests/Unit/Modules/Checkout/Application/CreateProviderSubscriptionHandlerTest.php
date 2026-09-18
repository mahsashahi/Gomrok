<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Checkout\Application;

use DateTimeImmutable;
use Gomrok\Modules\Checkout\Application\CreateProviderSubscription\CreateProviderSubscriptionCommand;
use Gomrok\Modules\Checkout\Application\CreateProviderSubscription\CreateProviderSubscriptionHandler;
use Gomrok\Modules\Checkout\Application\CreateProviderSubscription\CreateProviderSubscriptionResult;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPayableAmount;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Pricing\Application\PriceSource;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshot;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Application\Adapter\CreatePaymentCommand as ProviderCreatePaymentCommand;
use Gomrok\Modules\Providers\Application\Adapter\ParsedWebhookEvent;
use Gomrok\Modules\Providers\Application\Adapter\PaymentProviderPort;
use Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRequestFailed;
use Gomrok\Modules\Providers\Application\Adapter\RawWebhook;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot;
use Gomrok\Modules\Providers\Domain\ProviderCapabilities;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPricingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryProviderRoutingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryVoucherDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryVoucherRedemptionRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubPackageDirectory;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateProviderSubscriptionHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    private DateTimeImmutable $now;
    private InMemoryCheckoutAttemptRepository $attempts;
    private InMemoryProviderRoutingDecisionSnapshotRepository $routingSnapshots;
    private InMemoryPricingDecisionSnapshotRepository $pricingSnapshots;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private StubProviderAdapterFactory $adapterFactory;
    private FakePaymentProviderPort $adapter;
    private CreateProviderSubscriptionHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $this->attempts = new InMemoryCheckoutAttemptRepository();
        $this->routingSnapshots = new InMemoryProviderRoutingDecisionSnapshotRepository();
        $this->pricingSnapshots = new InMemoryPricingDecisionSnapshotRepository();
        $this->gatewayReferences = new InMemoryGatewayReferenceRepository();
        $this->adapter = new FakePaymentProviderPort();
        $this->adapterFactory = (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $this->adapter);

        $this->handler = $this->buildHandler($this->adapterFactory);
    }

    #[Test]
    public function createsTheProviderSubscriptionAndAdvancesTheAttempt(): void
    {
        $attemptId = $this->providerSelectedSubscriptionAttempt();

        $result = $this->handler->handle(new CreateProviderSubscriptionCommand(self::CLIENT, $attemptId));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof CreateProviderSubscriptionResult);
        self::assertSame('sub_1', $value->providerReference);
        self::assertSame('provider_checkout_created', $value->status);

        $attempt = $this->attempts->findById($attemptId);
        self::assertNotNull($attempt);
        self::assertSame(CheckoutAttemptStatus::ProviderCheckoutCreated, $attempt->status());

        $references = $this->gatewayReferences->forCheckoutAttempt($attemptId);
        self::assertCount(1, $references);
        self::assertSame('sub_1', $references[0]->referenceValue);
    }

    #[Test]
    public function passesTheSubscriptionIntervalToTheAdapter(): void
    {
        $attemptId = $this->providerSelectedSubscriptionAttempt();

        $this->handler->handle(new CreateProviderSubscriptionCommand(self::CLIENT, $attemptId));

        $command = $this->adapter->lastCreateSubscriptionCommand;
        self::assertNotNull($command);
        self::assertSame(SubscriptionInterval::Monthly, $command->interval);
        self::assertSame(2900, $command->amountMinor);
    }

    #[Test]
    public function rejectsAOneTimePaymentPurchaseType(): void
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::OneTimePayment, null, null, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);
        $attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $this->now);
        $attempt->transitionTo(CheckoutAttemptStatus::ProviderSelected, $this->now);
        $this->attempts->save($attempt);
        $this->seedPricing($attemptId, 2900);
        $this->routingSnapshots->save(new ProviderRoutingDecisionSnapshot(null, $attemptId, self::CLIENT, self::PROVIDER_ACCOUNT, 'card', 'one_time_payment', [], $this->now));

        $result = $this->handler->handle(new CreateProviderSubscriptionCommand(self::CLIENT, $attemptId));

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.not_a_subscription', $result->error()->code);
    }

    #[Test]
    public function aRealAttemptOwnedByAnotherClientIsNotFound(): void
    {
        $attemptId = $this->providerSelectedSubscriptionAttempt();

        $result = $this->handler->handle(new CreateProviderSubscriptionCommand(self::CLIENT + 1, $attemptId));

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.not_found', $result->error()->code);
        self::assertSame([], $this->gatewayReferences->forCheckoutAttempt($attemptId));
    }

    #[Test]
    public function rejectsAnAttemptThatHasNoProviderSelected(): void
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::Subscription, null, SubscriptionInterval::Monthly, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);

        $result = $this->handler->handle(new CreateProviderSubscriptionCommand(self::CLIENT, $attemptId));

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.provider_not_selected', $result->error()->code);
    }

    #[Test]
    public function rejectsAProviderThatDoesNotImplementSubscriptions(): void
    {
        $paymentOnlyAdapter = new class () implements PaymentProviderPort {
            public function createPayment(ProviderCreatePaymentCommand $command): ProviderPaymentResult
            {
                throw new \LogicException('not used in this test');
            }

            public function getPaymentStatus(string $providerReference): ProviderPaymentStatus
            {
                throw new \LogicException('not used in this test');
            }

            public function verifyWebhookSignature(RawWebhook $webhook): bool
            {
                return true;
            }

            public function parseWebhook(RawWebhook $webhook): ParsedWebhookEvent
            {
                throw new \LogicException('not used in this test');
            }

            public function mapProviderStatusToInternalStatus(string $providerStatus): PaymentStatus
            {
                return PaymentStatus::Pending;
            }

            public function getCapabilities(): ProviderCapabilities
            {
                return ProviderCapabilities::none();
            }
        };
        $factory = (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $paymentOnlyAdapter);
        $handler = $this->buildHandler($factory);
        $attemptId = $this->providerSelectedSubscriptionAttempt();

        $result = $handler->handle(new CreateProviderSubscriptionCommand(self::CLIENT, $attemptId));

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.subscriptions_not_supported', $result->error()->code);
    }

    #[Test]
    public function mapsAProviderAdapterExceptionToAnUpstreamFailure(): void
    {
        $attemptId = $this->providerSelectedSubscriptionAttempt();
        $this->adapter->throwOnCreateSubscription(new ProviderRequestFailed('provider is down'));

        $result = $this->handler->handle(new CreateProviderSubscriptionCommand(self::CLIENT, $attemptId));

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.provider_subscription_failed', $result->error()->code);
        self::assertSame(502, $result->error()->httpStatus());
    }

    private function buildHandler(StubProviderAdapterFactory $adapterFactory): CreateProviderSubscriptionHandler
    {
        return new CreateProviderSubscriptionHandler(
            $this->attempts,
            $this->routingSnapshots,
            new ResolveCheckoutPayableAmount($this->pricingSnapshots, new InMemoryVoucherDecisionSnapshotRepository(), new InMemoryVoucherRedemptionRepository()),
            (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro', 'Pro package'),
            $adapterFactory,
            $this->gatewayReferences,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
            'https://gomrok.example',
            'gomrokimo',
        );
    }

    private function providerSelectedSubscriptionAttempt(): int
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::Subscription, null, SubscriptionInterval::Monthly, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);
        $attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $this->now);
        $attempt->transitionTo(CheckoutAttemptStatus::ProviderSelected, $this->now);
        $this->attempts->save($attempt);

        $this->seedPricing($attemptId, 2900);
        $this->routingSnapshots->save(new ProviderRoutingDecisionSnapshot(null, $attemptId, self::CLIENT, self::PROVIDER_ACCOUNT, 'card', 'subscription', [], $this->now));

        return $attemptId;
    }

    private function seedPricing(int $attemptId, int $amountMinor): void
    {
        $price = new ResolvedPrice(self::PACKAGE, 'pro', $amountMinor, '29.00', 'EUR', PriceSource::Baseline, 'default', true, 'Pro', null, false, 0);
        $this->pricingSnapshots->save(PricingDecisionSnapshot::of($attemptId, self::CLIENT, $price, $this->now));
    }
}
