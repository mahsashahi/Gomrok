<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Payments\Application;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Application\CancelPayment\CancelPaymentCommand;
use Gomrok\Modules\Payments\Application\CancelPayment\CancelPaymentHandler;
use Gomrok\Modules\Payments\Application\CancelPayment\CancelPaymentResult;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Application\ResolvePaymentActionContext;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Application\ProviderCapabilityResolver;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionPaymentLink;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPaymentAttemptRepository;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryProviderRoutingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryProviderTransactionRepository;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use Gomrok\Tests\Support\InMemorySubscriptionPaymentLinkRepository;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\RecordingDomainEventDispatcher;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CancelPaymentHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const CHECKOUT_ATTEMPT = 100;
    private const SUBSCRIPTION_CHECKOUT_ATTEMPT = 200;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    private DateTimeImmutable $now;
    private InMemoryPaymentRepository $payments;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private InMemorySubscriptionPaymentLinkRepository $paymentLinks;
    private InMemorySubscriptionRepository $subscriptions;
    private FakePaymentProviderPort $adapter;
    private CancelPaymentHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');
        $this->payments = new InMemoryPaymentRepository();
        $this->gatewayReferences = new InMemoryGatewayReferenceRepository();
        $this->paymentLinks = new InMemorySubscriptionPaymentLinkRepository();
        $this->subscriptions = new InMemorySubscriptionRepository();
        $this->adapter = new FakePaymentProviderPort();
        $this->handler = $this->buildHandler('stripe');
    }

    #[Test]
    public function cancelsAPendingPayment(): void
    {
        $paymentId = $this->seedPayment(PaymentStatus::Pending);

        $result = $this->handler->handle(new CancelPaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof CancelPaymentResult);
        self::assertSame('canceled', $value->status);
        self::assertSame('cs_test_1', $this->adapter->lastCancelReference);

        $payment = $this->payments->findById($paymentId);
        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Canceled, $payment->status());
    }

    #[Test]
    public function rejectsAPaidPayment(): void
    {
        $this->seedPayment(PaymentStatus::Paid);

        $result = $this->handler->handle(new CancelPaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT));

        self::assertTrue($result->isErr());
        self::assertSame('payment.not_cancelable', $result->error()->code);
        self::assertNull($this->adapter->lastCancelReference);
    }

    #[Test]
    public function rejectsCancelWhenTheProviderDoesNotSupportIt(): void
    {
        $this->handler = $this->buildHandler('mollie');
        $this->seedPayment(PaymentStatus::Pending);

        $result = $this->handler->handle(new CancelPaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT));

        self::assertTrue($result->isErr());
        self::assertSame('payment.cancel_not_supported', $result->error()->code);
    }

    #[Test]
    public function cancelsARenewalOriginatedPaymentByPaymentIdViaTheSubscriptionPaymentLink(): void
    {
        $paymentId = $this->seedRenewalPayment(PaymentStatus::Pending);

        $result = $this->handler->handle(new CancelPaymentCommand(clientId: self::CLIENT, paymentId: $paymentId));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof CancelPaymentResult);
        self::assertSame('canceled', $value->status);
        self::assertSame('pi_renewal_1', $this->adapter->lastCancelReference);

        $payment = $this->payments->findById($paymentId);
        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Canceled, $payment->status());
    }

    #[Test]
    public function rejectsCancelOnARenewalPaymentWhenTheProviderDoesNotSupportIt(): void
    {
        $this->handler = $this->buildHandler('mollie');
        $paymentId = $this->seedRenewalPayment(PaymentStatus::Pending);

        $result = $this->handler->handle(new CancelPaymentCommand(clientId: self::CLIENT, paymentId: $paymentId));

        self::assertTrue($result->isErr());
        self::assertSame('payment.cancel_not_supported', $result->error()->code);
    }

    #[Test]
    public function aRenewalPaymentBelongingToAnotherClientIsNotFound(): void
    {
        $paymentId = $this->seedRenewalPayment(PaymentStatus::Pending);

        $result = $this->handler->handle(new CancelPaymentCommand(clientId: self::CLIENT + 1, paymentId: $paymentId));

        self::assertTrue($result->isErr());
        self::assertSame('payment.not_found', $result->error()->code);
    }

    private function buildHandler(string $providerTypeCode): CancelPaymentHandler
    {
        $routingSnapshots = new InMemoryProviderRoutingDecisionSnapshotRepository();
        $routingSnapshots->save(new ProviderRoutingDecisionSnapshot(null, self::CHECKOUT_ATTEMPT, self::CLIENT, self::PROVIDER_ACCOUNT, 'card', 'one_time_payment', [], $this->now));

        $accounts = (new StubProviderAccountDirectory())->add(self::PROVIDER_ACCOUNT, self::CLIENT, 'account-main', $providerTypeCode);

        $context = new ResolvePaymentActionContext(
            $routingSnapshots,
            $accounts,
            new ProviderCapabilityResolver(InMemoryProviderTypeDeclarations::withKnownProviders()),
            (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $this->adapter),
            $this->gatewayReferences,
            $this->paymentLinks,
            $this->subscriptions,
        );

        $recordTransaction = new RecordProviderTransactionHandler(
            $this->payments,
            new InMemoryPaymentAttemptRepository(),
            new InMemoryProviderTransactionRepository(),
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-13T12:00:00+00:00'),
            new RecordingDomainEventDispatcher(),
        );

        return new CancelPaymentHandler($this->payments, $context, $recordTransaction);
    }

    private function seedPayment(PaymentStatus $status): int
    {
        $payment = Payment::create(self::CLIENT, self::CHECKOUT_ATTEMPT, 'user-1', self::PACKAGE, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $this->now);
        $this->advanceTo($payment, $status);
        $this->payments->save($payment);
        $paymentId = $payment->id();
        \assert($paymentId !== null);

        $this->gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::CheckoutSession, 'cs_test_1', $paymentId, $this->now));

        return $paymentId;
    }

    /**
     * A renewal charge (Phase 26 Q2 / Phase 29 Q4): no checkout attempt of
     * its own, only linked to its subscription via `subscription_payment_links`
     * — mirrors exactly what {@see \Gomrok\Modules\Subscriptions\Application\RecordSubscriptionPayment\RecordSubscriptionPaymentHandler}
     * produces.
     */
    private function seedRenewalPayment(PaymentStatus $status): int
    {
        $subscription = Subscription::create(
            self::CLIENT,
            'user-1',
            self::SUBSCRIPTION_CHECKOUT_ATTEMPT,
            self::PACKAGE,
            self::PROVIDER_ACCOUNT,
            'EUR',
            2900,
            PaymentMethod::Card,
            SubscriptionInterval::Monthly,
            false,
            null,
            $this->now,
        );
        $this->subscriptions->save($subscription);
        $subscriptionId = $subscription->id();
        \assert($subscriptionId !== null);

        $payment = Payment::create(self::CLIENT, null, 'user-1', self::PACKAGE, 'DE', 'EUR', 2900, PurchaseType::Subscription, PaymentMethod::Card, SubscriptionInterval::Monthly, $this->now);
        $this->advanceTo($payment, $status);
        $this->payments->save($payment);
        $paymentId = $payment->id();
        \assert($paymentId !== null);

        $this->paymentLinks->save(SubscriptionPaymentLink::link($subscriptionId, $paymentId, null, null, $this->now));
        $this->gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::PaymentIntent, 'pi_renewal_1', $paymentId, $this->now));

        return $paymentId;
    }

    private function advanceTo(Payment $payment, PaymentStatus $target): void
    {
        $path = match ($target) {
            PaymentStatus::Pending => [PaymentStatus::Pending],
            PaymentStatus::Paid => [PaymentStatus::Pending, PaymentStatus::Paid],
            default => throw new \LogicException('unsupported target in this test'),
        };
        foreach ($path as $step) {
            $payment->transitionTo($step, $this->now);
        }
    }
}
