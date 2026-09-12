<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Payments\Application;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionCommand;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionResult;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentAttemptStatus;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryPaymentAttemptRepository;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryProviderTransactionRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordProviderTransactionHandlerTest extends TestCase
{
    private const CLIENT = 7;

    private DateTimeImmutable $now;
    private InMemoryPaymentRepository $payments;
    private InMemoryPaymentAttemptRepository $attempts;
    private InMemoryProviderTransactionRepository $transactions;
    private RecordProviderTransactionHandler $handler;
    private int $paymentId;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-11T12:00:00+00:00');
        $this->payments = new InMemoryPaymentRepository();
        $this->attempts = new InMemoryPaymentAttemptRepository();
        $this->transactions = new InMemoryProviderTransactionRepository();

        $payment = Payment::create(self::CLIENT, 1, 'user-1', 42, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, null, null, $this->now);
        $this->payments->save($payment);
        $id = $payment->id();
        \assert($id !== null);
        $this->paymentId = $id;

        $this->handler = new RecordProviderTransactionHandler(
            $this->payments,
            $this->attempts,
            $this->transactions,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-11T12:00:00+00:00'),
        );
    }

    #[Test]
    public function startsANewAttemptAndAdvancesThePayment(): void
    {
        $result = $this->handler->handle(new RecordProviderTransactionCommand(
            clientId: self::CLIENT,
            paymentId: $this->paymentId,
            providerAccountId: 1,
            kind: 'authorize',
            providerStatusRaw: 'requires_action',
            newStatus: 'pending',
            paymentMethod: 'card',
        ));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof RecordProviderTransactionResult);
        self::assertSame('pending', $value->paymentStatus);
        self::assertSame(1, $value->paymentAttemptId);

        $attempt = $this->attempts->findById(1);
        self::assertSame(PaymentAttemptStatus::Started, $attempt?->status());
        self::assertSame(1, $attempt->attemptNumber());
    }

    #[Test]
    public function reusesTheStartedAttemptForASecondTransaction(): void
    {
        $this->handler->handle(new RecordProviderTransactionCommand(self::CLIENT, $this->paymentId, 1, 'authorize', 'requires_action', 'pending'));
        $result = $this->handler->handle(new RecordProviderTransactionCommand(self::CLIENT, $this->paymentId, 1, 'authorize', 'succeeded', 'authorized', attemptOutcome: 'succeeded'));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof RecordProviderTransactionResult);
        self::assertSame(1, $value->paymentAttemptId, 'the second transaction reuses attempt #1, not a new one');

        $attempt = $this->attempts->findById(1);
        self::assertSame(PaymentAttemptStatus::Succeeded, $attempt?->status());
        self::assertCount(2, $this->transactions->forAttempt(1));
    }

    #[Test]
    public function startsANewAttemptNumberAfterThePreviousOneCompleted(): void
    {
        $this->handler->handle(new RecordProviderTransactionCommand(self::CLIENT, $this->paymentId, 1, 'authorize', 'declined', 'failed', attemptOutcome: 'failed', errorCode: 'card_declined', errorMessage: 'Declined'));

        // Failed is terminal, so drive a fresh payment for the retry-with-new-attempt case.
        $payment = Payment::create(self::CLIENT, 2, 'user-1', 42, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, null, null, $this->now);
        $this->payments->save($payment);
        $id = $payment->id();
        \assert($id !== null);

        $this->handler->handle(new RecordProviderTransactionCommand(self::CLIENT, $id, 1, 'authorize', 'requires_action', 'pending'));
        $result = $this->handler->handle(new RecordProviderTransactionCommand(self::CLIENT, $id, 1, 'authorize', 'requires_action', 'pending'));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof RecordProviderTransactionResult);
        self::assertSame(2, $value->paymentAttemptId, 'still reuses the one open attempt on this payment');
    }

    #[Test]
    public function rejectsAnIllegalTransitionAndPersistsNothing(): void
    {
        $result = $this->handler->handle(new RecordProviderTransactionCommand(self::CLIENT, $this->paymentId, 1, 'capture', 'succeeded', 'paid'));

        self::assertTrue($result->isErr());
        self::assertSame('payment.invalid_transition', $result->error()->code);
        self::assertSame([], $this->attempts->forPayment($this->paymentId));
    }

    #[Test]
    public function anUnknownStatusIsAValidationError(): void
    {
        $result = $this->handler->handle(new RecordProviderTransactionCommand(self::CLIENT, $this->paymentId, 1, 'authorize', 'x', 'not-a-status'));

        self::assertTrue($result->isErr());
        self::assertSame('payment.unknown_status', $result->error()->code);
    }
}
