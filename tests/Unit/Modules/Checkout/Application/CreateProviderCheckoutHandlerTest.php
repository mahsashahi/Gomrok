<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Checkout\Application;

use DateTimeImmutable;
use Gomrok\Modules\Checkout\Application\CreateProviderCheckout\CreateProviderCheckoutCommand;
use Gomrok\Modules\Checkout\Application\CreateProviderCheckout\CreateProviderCheckoutHandler;
use Gomrok\Modules\Checkout\Application\CreateProviderCheckout\CreateProviderCheckoutResult;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPayableAmount;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Pricing\Application\PriceSource;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshot;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRequestFailed;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot;
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

final class CreateProviderCheckoutHandlerTest extends TestCase
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
    private CreateProviderCheckoutHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');
        $this->attempts = new InMemoryCheckoutAttemptRepository();
        $this->routingSnapshots = new InMemoryProviderRoutingDecisionSnapshotRepository();
        $this->pricingSnapshots = new InMemoryPricingDecisionSnapshotRepository();
        $this->gatewayReferences = new InMemoryGatewayReferenceRepository();
        $this->adapter = new FakePaymentProviderPort(new ProviderPaymentResult('cs_test_1', 'https://provider.example/checkout/cs_test_1', 'open'));
        $this->adapterFactory = (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $this->adapter);

        $this->handler = new CreateProviderCheckoutHandler(
            $this->attempts,
            $this->routingSnapshots,
            new ResolveCheckoutPayableAmount($this->pricingSnapshots, new InMemoryVoucherDecisionSnapshotRepository(), new InMemoryVoucherRedemptionRepository()),
            (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro', 'Pro package'),
            $this->adapterFactory,
            $this->gatewayReferences,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-13T12:00:00+00:00'),
            'https://gomrok.example',
            'gomrokimo',
        );
    }

    #[Test]
    public function createsTheProviderCheckoutAndAdvancesTheAttempt(): void
    {
        $attemptId = $this->providerSelectedAttempt();

        $result = $this->handler->handle(new CreateProviderCheckoutCommand(self::CLIENT, $attemptId));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof CreateProviderCheckoutResult);
        self::assertSame('cs_test_1', $value->providerReference);
        self::assertSame('https://provider.example/checkout/cs_test_1', $value->redirectUrl);
        self::assertSame('provider_checkout_created', $value->status);

        $attempt = $this->attempts->findById($attemptId);
        self::assertNotNull($attempt);
        self::assertSame(CheckoutAttemptStatus::ProviderCheckoutCreated, $attempt->status());
        self::assertNotNull($attempt->hashReturnToken());

        $references = $this->gatewayReferences->forCheckoutAttempt($attemptId);
        self::assertCount(1, $references);
        self::assertSame('cs_test_1', $references[0]->referenceValue);
        self::assertSame($attemptId, $references[0]->checkoutAttemptId);
        self::assertNull($references[0]->paymentId);
    }

    #[Test]
    public function buildsAReturnUrlCarryingTheIssuedToken(): void
    {
        $attemptId = $this->providerSelectedAttempt();

        $this->handler->handle(new CreateProviderCheckoutCommand(self::CLIENT, $attemptId));

        $command = $this->adapter->lastCommand;
        self::assertNotNull($command);
        self::assertStringStartsWith("https://gomrok.example/payments/return?return_token={$attemptId}_", $command->successUrl);
        self::assertStringEndsWith('&outcome=success', $command->successUrl);
        self::assertStringEndsWith('&outcome=cancel', $command->cancelUrl);
    }

    #[Test]
    public function aRealAttemptOwnedByAnotherClientIsNotFound(): void
    {
        $attemptId = $this->providerSelectedAttempt();

        $result = $this->handler->handle(new CreateProviderCheckoutCommand(self::CLIENT + 1, $attemptId));

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.not_found', $result->error()->code);
        self::assertNull($this->adapter->lastCommand);
    }

    #[Test]
    public function rejectsAnAttemptThatHasNoProviderSelected(): void
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::OneTimePayment, null, null, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);

        $result = $this->handler->handle(new CreateProviderCheckoutCommand(self::CLIENT, $attemptId));

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.provider_not_selected', $result->error()->code);
    }

    #[Test]
    public function rejectsASubscriptionPurchaseType(): void
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::Subscription, null, null, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);
        $attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $this->now);
        $attempt->transitionTo(CheckoutAttemptStatus::ProviderSelected, $this->now);
        $this->attempts->save($attempt);
        $this->seedPricing($attemptId, 2900);
        $this->routingSnapshots->save(new ProviderRoutingDecisionSnapshot(null, $attemptId, self::CLIENT, self::PROVIDER_ACCOUNT, 'card', 'subscription', [], $this->now));

        $result = $this->handler->handle(new CreateProviderCheckoutCommand(self::CLIENT, $attemptId));

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.subscription_not_supported_here', $result->error()->code);
    }

    #[Test]
    public function mapsAProviderAdapterExceptionToAnUpstreamFailure(): void
    {
        $attemptId = $this->providerSelectedAttempt();
        $this->adapter->throwOnCreatePayment(new ProviderRequestFailed('provider is down'));

        $result = $this->handler->handle(new CreateProviderCheckoutCommand(self::CLIENT, $attemptId));

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.provider_checkout_failed', $result->error()->code);
        self::assertSame(502, $result->error()->httpStatus());
    }

    #[Test]
    public function mapsAnUnsupportedProviderTypeToAValidationError(): void
    {
        $attemptId = $this->providerSelectedAttempt(providerAccountId: 999);
        $this->adapterFactory->withUnsupportedProviderType(999, "No adapter implemented for provider type 'ziraat' yet.");

        $result = $this->handler->handle(new CreateProviderCheckoutCommand(self::CLIENT, $attemptId));

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.provider_not_implemented', $result->error()->code);
    }

    private function providerSelectedAttempt(int $providerAccountId = self::PROVIDER_ACCOUNT): int
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::OneTimePayment, null, null, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);
        $attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $this->now);
        $attempt->transitionTo(CheckoutAttemptStatus::ProviderSelected, $this->now);
        $this->attempts->save($attempt);

        $this->seedPricing($attemptId, 2900);
        $this->routingSnapshots->save(new ProviderRoutingDecisionSnapshot(null, $attemptId, self::CLIENT, $providerAccountId, 'card', 'one_time_payment', [], $this->now));

        return $attemptId;
    }

    private function seedPricing(int $attemptId, int $amountMinor): void
    {
        $price = new ResolvedPrice(self::PACKAGE, 'pro', $amountMinor, '29.00', 'EUR', PriceSource::Baseline, 'default', true, 'Pro', null, false, 0);
        $this->pricingSnapshots->save(PricingDecisionSnapshot::of($attemptId, self::CLIENT, $price, $this->now));
    }
}
