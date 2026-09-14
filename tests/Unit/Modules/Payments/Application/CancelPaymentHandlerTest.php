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
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CancelPaymentHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const CHECKOUT_ATTEMPT = 100;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    private DateTimeImmutable $now;
    private InMemoryPaymentRepository $payments;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private FakePaymentProviderPort $adapter;
    private CancelPaymentHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');
        $this->payments = new InMemoryPaymentRepository();
        $this->gatewayReferences = new InMemoryGatewayReferenceRepository();
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
        );

        $recordTransaction = new RecordProviderTransactionHandler(
            $this->payments,
            new InMemoryPaymentAttemptRepository(),
            new InMemoryProviderTransactionRepository(),
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-13T12:00:00+00:00'),
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
