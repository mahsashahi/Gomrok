<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Reconciliation\Application\Jobs;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentAttempt;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Reconciliation\Application\Jobs\PaymentReconciliationScanHandler;
use Gomrok\Modules\Reconciliation\Application\ReconciliationFindingFilter;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPaymentAttemptRepository;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryReconciliationFindingRepository;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentReconciliationScanHandlerTest extends TestCase
{
    private const CLIENT = 1;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;
    private const CHECKOUT_ATTEMPT = 100;

    private InMemoryPaymentRepository $payments;
    private InMemoryPaymentAttemptRepository $paymentAttempts;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private InMemoryReconciliationFindingRepository $findings;
    private FakePaymentProviderPort $adapter;
    private FrozenClock $clock;
    private PaymentReconciliationScanHandler $handler;

    protected function setUp(): void
    {
        $this->payments = new InMemoryPaymentRepository();
        $this->paymentAttempts = new InMemoryPaymentAttemptRepository();
        $this->gatewayReferences = new InMemoryGatewayReferenceRepository();
        $this->findings = new InMemoryReconciliationFindingRepository();
        $this->adapter = new FakePaymentProviderPort();
        $this->clock = new FrozenClock('2026-09-17T12:00:00+00:00');

        $this->handler = new PaymentReconciliationScanHandler(
            $this->payments,
            $this->paymentAttempts,
            $this->gatewayReferences,
            (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $this->adapter),
            $this->findings,
            $this->clock,
        );
    }

    private function seedPendingPayment(): int
    {
        $payment = Payment::create(self::CLIENT, self::CHECKOUT_ATTEMPT, 'user-1', self::PACKAGE, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $this->clock->now());
        $payment->transitionTo(PaymentStatus::Pending, $this->clock->now());
        $this->payments->save($payment);
        $id = $payment->id();
        \assert($id !== null);

        $this->paymentAttempts->save(PaymentAttempt::start($id, self::PROVIDER_ACCOUNT, 1, PaymentMethod::Card, $this->clock->now()));
        $this->gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::PaymentIntent, 'pi_1', $id, $this->clock->now()));

        return $id;
    }

    #[Test]
    public function exposesItsTypeAndRecurrence(): void
    {
        self::assertSame('payment_reconciliation_scan', $this->handler->type());
        self::assertSame(30, $this->handler->recurrenceIntervalMinutes());
    }

    #[Test]
    public function recordsAFindingWhenTheProviderStatusDisagreesWithTheLocalStatus(): void
    {
        $paymentId = $this->seedPendingPayment();
        $this->adapter->createPaymentResultStatus('paid');

        $job = Job::schedule('payment_reconciliation_scan', null, $this->clock->now(), $this->clock->now());
        $result = $this->handler->handle($job);

        self::assertTrue($result->success);
        self::assertSame(['scanned' => 1, 'drifted' => 1, 'skipped' => 0], $result->summary);

        $findings = $this->findings->search(new ReconciliationFindingFilter(clientId: self::CLIENT, resolution: 'all'));
        self::assertCount(1, $findings);
        self::assertSame($paymentId, $findings[0]->targetId());
        self::assertSame('pending', $findings[0]->localStatus());
        self::assertSame('paid', $findings[0]->mappedProviderStatus());
    }

    #[Test]
    public function recordsNoFindingWhenTheProviderStatusAgrees(): void
    {
        $this->seedPendingPayment();
        $this->adapter->createPaymentResultStatus('open');

        $job = Job::schedule('payment_reconciliation_scan', null, $this->clock->now(), $this->clock->now());
        $result = $this->handler->handle($job);

        self::assertSame(['scanned' => 1, 'drifted' => 0, 'skipped' => 0], $result->summary);
    }

    #[Test]
    public function skipsAPaymentWithNoGatewayReferenceToCheck(): void
    {
        $payment = Payment::create(self::CLIENT, self::CHECKOUT_ATTEMPT, 'user-1', self::PACKAGE, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $this->clock->now());
        $payment->transitionTo(PaymentStatus::Pending, $this->clock->now());
        $this->payments->save($payment);
        $id = $payment->id();
        \assert($id !== null);
        $this->paymentAttempts->save(PaymentAttempt::start($id, self::PROVIDER_ACCOUNT, 1, PaymentMethod::Card, $this->clock->now()));

        $job = Job::schedule('payment_reconciliation_scan', null, $this->clock->now(), $this->clock->now());
        $result = $this->handler->handle($job);

        self::assertSame(['scanned' => 1, 'drifted' => 0, 'skipped' => 1], $result->summary);
    }
}
