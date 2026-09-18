<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Payments\Application;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Application\RefundPayment\RefundPaymentCommand;
use Gomrok\Modules\Payments\Application\RefundPayment\RefundPaymentHandler;
use Gomrok\Modules\Payments\Application\RefundPayment\RefundPaymentResult;
use Gomrok\Modules\Payments\Application\ResolvePaymentActionContext;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRefundResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRequestFailed;
use Gomrok\Modules\Providers\Application\ProviderCapabilityResolver;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot;
use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderCapabilities;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclaration;
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

final class RefundPaymentHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const CHECKOUT_ATTEMPT = 100;
    private const SUBSCRIPTION_CHECKOUT_ATTEMPT = 200;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;
    private const AMOUNT_MINOR = 2900;

    private DateTimeImmutable $now;
    private InMemoryPaymentRepository $payments;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private InMemorySubscriptionPaymentLinkRepository $paymentLinks;
    private InMemorySubscriptionRepository $subscriptions;
    private FakePaymentProviderPort $adapter;
    private RefundPaymentHandler $handler;

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
    public function fullyRefundsAPaidPayment(): void
    {
        $paymentId = $this->seedPaidPayment();
        $this->adapter->refundResult(new ProviderRefundResult('re_1', self::AMOUNT_MINOR, 'succeeded'));

        $result = $this->handler->handle(new RefundPaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof RefundPaymentResult);
        self::assertSame('refunded', $value->status);
        self::assertSame('re_1', $value->providerReference);
        self::assertSame(self::AMOUNT_MINOR, $value->refundedMinor);
        self::assertNull($this->adapter->lastRefundAmountMinor);

        $payment = $this->payments->findById($paymentId);
        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Refunded, $payment->status());
    }

    #[Test]
    public function partiallyRefundsAPaidPayment(): void
    {
        $this->seedPaidPayment();
        $this->adapter->refundResult(new ProviderRefundResult('re_1', 1000, 'succeeded'));

        $result = $this->handler->handle(new RefundPaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT, 1000));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof RefundPaymentResult);
        self::assertSame('partially_refunded', $value->status);
        self::assertSame(1000, $this->adapter->lastRefundAmountMinor);
    }

    #[Test]
    public function rejectsARefundAmountLargerThanThePayment(): void
    {
        $this->seedPaidPayment();

        $result = $this->handler->handle(new RefundPaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT, self::AMOUNT_MINOR + 1));

        self::assertTrue($result->isErr());
        self::assertSame('payment.refund_amount_exceeds_payment', $result->error()->code);
    }

    #[Test]
    public function rejectsAPaymentThatIsNotPaid(): void
    {
        $this->seedPayment(PaymentStatus::Created);

        $result = $this->handler->handle(new RefundPaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT));

        self::assertTrue($result->isErr());
        self::assertSame('payment.not_refundable', $result->error()->code);
    }

    #[Test]
    public function rejectsRefundWhenTheProviderDoesNotSupportItAtAll(): void
    {
        // Ziraat declares neither Refund nor PartialRefund.
        $this->handler = $this->buildHandler('ziraat');
        $this->seedPaidPayment();

        $result = $this->handler->handle(new RefundPaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT));

        self::assertTrue($result->isErr());
        self::assertSame('payment.refund_not_supported', $result->error()->code);
    }

    #[Test]
    public function rejectsAPartialRefundWhenTheProviderOnlySupportsFullRefunds(): void
    {
        $declarations = new InMemoryProviderTypeDeclarations();
        $declarations->add(new ProviderTypeDeclaration('full-refund-only', [PurchaseType::OneTimePayment], ProviderCapabilities::of(Capability::Refund)));
        $accounts = (new StubProviderAccountDirectory())->add(self::PROVIDER_ACCOUNT, self::CLIENT, 'account-main', 'full-refund-only');
        $routingSnapshots = new InMemoryProviderRoutingDecisionSnapshotRepository();
        $routingSnapshots->save(new ProviderRoutingDecisionSnapshot(null, self::CHECKOUT_ATTEMPT, self::CLIENT, self::PROVIDER_ACCOUNT, 'card', 'one_time_payment', [], $this->now));
        $context = new ResolvePaymentActionContext(
            $routingSnapshots,
            $accounts,
            new ProviderCapabilityResolver($declarations),
            (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $this->adapter),
            $this->gatewayReferences,
            $this->paymentLinks,
            $this->subscriptions,
        );
        $this->handler = new RefundPaymentHandler($this->payments, $context, new RecordProviderTransactionHandler(
            $this->payments,
            new InMemoryPaymentAttemptRepository(),
            new InMemoryProviderTransactionRepository(),
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-13T12:00:00+00:00'),
            new RecordingDomainEventDispatcher(),
        ));
        $this->seedPaidPayment();

        $result = $this->handler->handle(new RefundPaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT, 1000));

        self::assertTrue($result->isErr());
        self::assertSame('payment.partial_refund_not_supported', $result->error()->code);
    }

    #[Test]
    public function mapsAProviderAdapterExceptionToAnUpstreamFailure(): void
    {
        $this->seedPaidPayment();
        $this->adapter->throwOnRefund(new ProviderRequestFailed('provider is down'));

        $result = $this->handler->handle(new RefundPaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT));

        self::assertTrue($result->isErr());
        self::assertSame('payment.refund_failed', $result->error()->code);
        self::assertSame(502, $result->error()->httpStatus());
    }

    #[Test]
    public function fullyRefundsARenewalOriginatedPaymentByPaymentIdViaTheSubscriptionPaymentLink(): void
    {
        $paymentId = $this->seedRenewalPaidPayment();
        $this->adapter->refundResult(new ProviderRefundResult('re_renewal_1', self::AMOUNT_MINOR, 'succeeded'));

        $result = $this->handler->handle(new RefundPaymentCommand(clientId: self::CLIENT, paymentId: $paymentId));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof RefundPaymentResult);
        self::assertSame('refunded', $value->status);
        self::assertSame('re_renewal_1', $value->providerReference);

        $payment = $this->payments->findById($paymentId);
        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Refunded, $payment->status());
    }

    #[Test]
    public function rejectsRefundOnARenewalPaymentWhenTheProviderDoesNotSupportItAtAll(): void
    {
        $this->handler = $this->buildHandler('ziraat');
        $paymentId = $this->seedRenewalPaidPayment();

        $result = $this->handler->handle(new RefundPaymentCommand(clientId: self::CLIENT, paymentId: $paymentId));

        self::assertTrue($result->isErr());
        self::assertSame('payment.refund_not_supported', $result->error()->code);
    }

    private function buildHandler(string $providerTypeCode): RefundPaymentHandler
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

        return new RefundPaymentHandler($this->payments, $context, $recordTransaction);
    }

    private function seedPaidPayment(): int
    {
        return $this->seedPayment(PaymentStatus::Paid);
    }

    /**
     * A renewal charge (Phase 26 Q2 / Phase 29 Q4): no checkout attempt of
     * its own, only linked to its subscription via `subscription_payment_links`
     * — mirrors exactly what {@see \Gomrok\Modules\Subscriptions\Application\RecordSubscriptionPayment\RecordSubscriptionPaymentHandler}
     * produces.
     */
    private function seedRenewalPaidPayment(): int
    {
        $subscription = Subscription::create(
            self::CLIENT,
            'user-1',
            self::SUBSCRIPTION_CHECKOUT_ATTEMPT,
            self::PACKAGE,
            self::PROVIDER_ACCOUNT,
            'EUR',
            self::AMOUNT_MINOR,
            PaymentMethod::Card,
            SubscriptionInterval::Monthly,
            false,
            null,
            $this->now,
        );
        $this->subscriptions->save($subscription);
        $subscriptionId = $subscription->id();
        \assert($subscriptionId !== null);

        $payment = Payment::create(self::CLIENT, null, 'user-1', self::PACKAGE, 'DE', 'EUR', self::AMOUNT_MINOR, PurchaseType::Subscription, PaymentMethod::Card, SubscriptionInterval::Monthly, $this->now);
        $this->advanceTo($payment, PaymentStatus::Paid);
        $this->payments->save($payment);
        $paymentId = $payment->id();
        \assert($paymentId !== null);

        $this->paymentLinks->save(SubscriptionPaymentLink::link($subscriptionId, $paymentId, null, null, $this->now));
        $this->gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::PaymentIntent, 'pi_renewal_1', $paymentId, $this->now));

        return $paymentId;
    }

    private function seedPayment(PaymentStatus $status): int
    {
        $payment = Payment::create(self::CLIENT, self::CHECKOUT_ATTEMPT, 'user-1', self::PACKAGE, 'DE', 'EUR', self::AMOUNT_MINOR, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $this->now);
        $this->advanceTo($payment, $status);
        $this->payments->save($payment);
        $paymentId = $payment->id();
        \assert($paymentId !== null);

        $this->gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::CheckoutSession, 'cs_test_1', $paymentId, $this->now));

        return $paymentId;
    }

    private function advanceTo(Payment $payment, PaymentStatus $target): void
    {
        $path = match ($target) {
            PaymentStatus::Created => [],
            PaymentStatus::Paid => [PaymentStatus::Pending, PaymentStatus::Paid],
            default => throw new \LogicException('unsupported target in this test'),
        };
        foreach ($path as $step) {
            $payment->transitionTo($step, $this->now);
        }
    }
}
