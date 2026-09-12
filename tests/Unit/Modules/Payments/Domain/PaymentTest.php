<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Payments\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-11T12:00:00+00:00');
    }

    #[Test]
    public function startsInTheCreatedState(): void
    {
        $payment = $this->payment();

        self::assertSame(PaymentStatus::Created, $payment->status());
        self::assertSame(2900, $payment->amountMinor());
        self::assertSame('EUR', $payment->currencyCode());
        self::assertSame('DE', $payment->country());
    }

    #[Test]
    public function happyPathThroughToRefunded(): void
    {
        $payment = $this->payment();

        self::assertNull($payment->transitionTo(PaymentStatus::Pending, $this->now));
        self::assertNull($payment->transitionTo(PaymentStatus::Authorized, $this->now));
        self::assertNull($payment->transitionTo(PaymentStatus::Paid, $this->now));
        self::assertNull($payment->transitionTo(PaymentStatus::Refunded, $this->now));
        self::assertSame(PaymentStatus::Refunded, $payment->status());
    }

    #[Test]
    public function aDisputeCanResolveBackToPaid(): void
    {
        $payment = $this->payment();
        $payment->transitionTo(PaymentStatus::Pending, $this->now);
        $payment->transitionTo(PaymentStatus::Paid, $this->now);

        self::assertNull($payment->transitionTo(PaymentStatus::Disputed, $this->now));
        self::assertNull($payment->transitionTo(PaymentStatus::Paid, $this->now));
        self::assertSame(PaymentStatus::Paid, $payment->status());
    }

    #[Test]
    public function sameStatusIsAnIdempotentNoOp(): void
    {
        $payment = $this->payment();
        $payment->transitionTo(PaymentStatus::Pending, $this->now);

        self::assertNull($payment->transitionTo(PaymentStatus::Pending, $this->now));
        self::assertSame(PaymentStatus::Pending, $payment->status());
    }

    #[Test]
    public function skippingDirectlyToAnUnreachableStatusIsRejected(): void
    {
        $payment = $this->payment();

        self::assertSame('payment.invalid_transition', $payment->transitionTo(PaymentStatus::Paid, $this->now)?->code);
        self::assertSame(PaymentStatus::Created, $payment->status());
    }

    #[Test]
    public function noTransitionIsAllowedOnceTerminalEvenARepeatOfTheCurrentStatus(): void
    {
        $payment = $this->payment();
        $payment->transitionTo(PaymentStatus::Canceled, $this->now);

        self::assertSame('payment.terminal', $payment->transitionTo(PaymentStatus::Pending, $this->now)?->code);
        self::assertSame('payment.terminal', $payment->transitionTo(PaymentStatus::Canceled, $this->now)?->code);
    }

    #[Test]
    public function failedCapturesTheErrorCodeAndMessage(): void
    {
        $payment = $this->payment();
        $payment->transitionTo(PaymentStatus::Pending, $this->now);

        self::assertNull($payment->transitionTo(PaymentStatus::Failed, $this->now, 'provider_declined', 'Card declined'));
        self::assertSame('provider_declined', $payment->errorCode());
        self::assertSame('Card declined', $payment->errorMessage());
    }

    private function payment(): Payment
    {
        return Payment::create(7, 42, 'user-1', 99, 'de', 'eur', 2900, PurchaseType::OneTimePayment, null, null, $this->now);
    }
}
