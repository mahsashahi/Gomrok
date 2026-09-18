<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Payments\Application;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Application\CapturePayment\CapturePaymentCommand;
use Gomrok\Modules\Payments\Application\CapturePayment\CapturePaymentHandler;
use Gomrok\Modules\Payments\Application\CapturePayment\CapturePaymentResult;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Application\ResolvePaymentActionContext;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentResult;
use Gomrok\Modules\Providers\Application\Adapter\ProviderRequestFailed;
use Gomrok\Modules\Providers\Application\ProviderCapabilityResolver;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPaymentAttemptRepository;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryProviderRoutingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryProviderTransactionRepository;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\RecordingDomainEventDispatcher;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CapturePaymentHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const CHECKOUT_ATTEMPT = 100;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    private DateTimeImmutable $now;
    private InMemoryPaymentRepository $payments;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private FakePaymentProviderPort $adapter;
    private CapturePaymentHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');
        $this->payments = new InMemoryPaymentRepository();
        $this->gatewayReferences = new InMemoryGatewayReferenceRepository();
        $this->adapter = new FakePaymentProviderPort();
        $this->handler = $this->buildHandler('stripe');
    }

    #[Test]
    public function capturesAnAuthorizedPaymentAndMarksItPaid(): void
    {
        $paymentId = $this->seedPayment(PaymentStatus::Authorized);
        $this->adapter->captureResult(new ProviderPaymentResult('pi_1', '', 'succeeded'));

        $result = $this->handler->handle(new CapturePaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof CapturePaymentResult);
        self::assertSame('paid', $value->status);
        self::assertSame('pi_1', $value->providerReference);
        self::assertSame('cs_test_1', $this->adapter->lastCaptureReference);

        $payment = $this->payments->findById($paymentId);
        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Paid, $payment->status());
    }

    #[Test]
    public function usesTheDeeperPaymentIntentReferenceWhenOneWasRecorded(): void
    {
        $this->seedPayment(PaymentStatus::Authorized, withDeepReference: true);

        $this->handler->handle(new CapturePaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT));

        self::assertSame('pi_deep_1', $this->adapter->lastCaptureReference);
    }

    #[Test]
    public function rejectsAPaymentThatIsNotAuthorized(): void
    {
        $this->seedPayment(PaymentStatus::Created);

        $result = $this->handler->handle(new CapturePaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT));

        self::assertTrue($result->isErr());
        self::assertSame('payment.not_authorized', $result->error()->code);
        self::assertNull($this->adapter->lastCaptureReference);
    }

    #[Test]
    public function rejectsCaptureWhenTheProviderDoesNotSupportIt(): void
    {
        $this->handler = $this->buildHandler('mollie');
        $this->seedPayment(PaymentStatus::Authorized);

        $result = $this->handler->handle(new CapturePaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT));

        self::assertTrue($result->isErr());
        self::assertSame('payment.capture_not_supported', $result->error()->code);
    }

    #[Test]
    public function mapsAProviderAdapterExceptionToAnUpstreamFailure(): void
    {
        $this->seedPayment(PaymentStatus::Authorized);
        $this->adapter->throwOnCapture(new ProviderRequestFailed('provider is down'));

        $result = $this->handler->handle(new CapturePaymentCommand(self::CLIENT, self::CHECKOUT_ATTEMPT));

        self::assertTrue($result->isErr());
        self::assertSame('payment.capture_failed', $result->error()->code);
        self::assertSame(502, $result->error()->httpStatus());
    }

    private function buildHandler(string $providerTypeCode): CapturePaymentHandler
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

        return new CapturePaymentHandler($this->payments, $context, $recordTransaction);
    }

    private function seedPayment(PaymentStatus $status, bool $withDeepReference = false): int
    {
        $payment = Payment::create(self::CLIENT, self::CHECKOUT_ATTEMPT, 'user-1', self::PACKAGE, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $this->now);
        $this->advanceTo($payment, $status);
        $this->payments->save($payment);
        $paymentId = $payment->id();
        \assert($paymentId !== null);

        $this->gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::CheckoutSession, 'cs_test_1', $paymentId, $this->now));
        if ($withDeepReference) {
            $this->gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::PaymentIntent, 'pi_deep_1', $paymentId, $this->now));
        }

        return $paymentId;
    }

    private function advanceTo(Payment $payment, PaymentStatus $target): void
    {
        $path = match ($target) {
            PaymentStatus::Created => [],
            PaymentStatus::Authorized => [PaymentStatus::Pending, PaymentStatus::Authorized],
            default => throw new \LogicException('unsupported target in this test'),
        };
        foreach ($path as $step) {
            $payment->transitionTo($step, $this->now);
        }
    }
}
