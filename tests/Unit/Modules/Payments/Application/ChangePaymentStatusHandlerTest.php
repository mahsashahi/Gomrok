<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Payments\Application;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Application\ChangePaymentStatus\ChangePaymentStatusHandler;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChangePaymentStatusHandlerTest extends TestCase
{
    private const CLIENT = 7;

    #[Test]
    public function changesStatusAndAudits(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00+00:00');
        $payments = new InMemoryPaymentRepository();
        $payment = Payment::create(self::CLIENT, 1, 'user-1', 42, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, null, null, $now);
        $payments->save($payment);
        $id = $payment->id();
        \assert($id !== null);

        $audit = new RecordingAuditLogWriter();
        $handler = new ChangePaymentStatusHandler($payments, $audit, new SynchronousTransactions(), new FrozenClock('2026-09-11T12:00:00+00:00'));

        $result = $handler->handle($id, self::CLIENT, 'canceled');

        self::assertTrue($result->isOk());
        self::assertSame(PaymentStatus::Canceled, $payments->findById($id)?->status());
        self::assertNotEmpty($audit->entries);
    }

    #[Test]
    public function rejectsAnotherClientsPayment(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00+00:00');
        $payments = new InMemoryPaymentRepository();
        $payment = Payment::create(self::CLIENT, 1, 'user-1', 42, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, null, null, $now);
        $payments->save($payment);
        $id = $payment->id();
        \assert($id !== null);

        $handler = new ChangePaymentStatusHandler($payments, new RecordingAuditLogWriter(), new SynchronousTransactions(), new FrozenClock('2026-09-11T12:00:00+00:00'));

        $result = $handler->handle($id, 999, 'canceled');

        self::assertTrue($result->isErr());
        self::assertSame('payment.not_found', $result->error()->code);
    }

    #[Test]
    public function unknownStatusIsAValidationError(): void
    {
        $handler = new ChangePaymentStatusHandler(new InMemoryPaymentRepository(), new RecordingAuditLogWriter(), new SynchronousTransactions(), new FrozenClock('2026-09-11T12:00:00+00:00'));

        $result = $handler->handle(1, self::CLIENT, 'not-a-status');

        self::assertTrue($result->isErr());
        self::assertSame('payment.unknown_status', $result->error()->code);
    }
}
