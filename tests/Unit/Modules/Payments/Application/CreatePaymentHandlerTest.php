<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Payments\Application;

use DateTimeImmutable;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Payments\Application\CreatePayment\CreatePaymentCommand;
use Gomrok\Modules\Payments\Application\CreatePayment\CreatePaymentHandler;
use Gomrok\Modules\Payments\Application\CreatePayment\CreatePaymentResult;
use Gomrok\Modules\Pricing\Application\PriceSource;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshot;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Modules\Vouchers\Domain\VoucherDecisionSnapshot;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemption;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryPricingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryVoucherDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryVoucherRedemptionRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreatePaymentHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;

    private DateTimeImmutable $now;
    private InMemoryCheckoutAttemptRepository $attempts;
    private InMemoryPaymentRepository $payments;
    private InMemoryPricingDecisionSnapshotRepository $pricingSnapshots;
    private InMemoryVoucherDecisionSnapshotRepository $voucherSnapshots;
    private InMemoryVoucherRedemptionRepository $redemptions;
    private CreatePaymentHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-11T12:00:00+00:00');
        $this->attempts = new InMemoryCheckoutAttemptRepository();
        $this->payments = new InMemoryPaymentRepository();
        $this->pricingSnapshots = new InMemoryPricingDecisionSnapshotRepository();
        $this->voucherSnapshots = new InMemoryVoucherDecisionSnapshotRepository();
        $this->redemptions = new InMemoryVoucherRedemptionRepository();

        $this->handler = new CreatePaymentHandler(
            $this->attempts,
            $this->payments,
            $this->pricingSnapshots,
            $this->voucherSnapshots,
            $this->redemptions,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-11T12:00:00+00:00'),
        );
    }

    #[Test]
    public function createsAPaymentFromAConfirmedAttemptAndConvertsIt(): void
    {
        $attemptId = $this->confirmedAttempt();
        $this->seedPricing($attemptId, 2900);

        $result = $this->handler->handle(new CreatePaymentCommand(self::CLIENT, $attemptId));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof CreatePaymentResult);
        self::assertSame('created', $value->status);
        self::assertSame(2900, $value->amountMinor);
        self::assertSame('EUR', $value->currencyCode);

        $attempt = $this->attempts->findById($attemptId);
        self::assertSame(CheckoutAttemptStatus::ConvertedToPayment, $attempt?->status());
    }

    #[Test]
    public function usesTheVouchersPayableAmountWhenAVoucherWasReserved(): void
    {
        $attemptId = $this->confirmedAttempt();
        $this->seedPricing($attemptId, 2900);

        $voucher = Voucher::create(self::CLIENT, 'WELCOME10', 'Welcome', null, null, null, false, null, null, DefaultDiscountType::Percentage, 1000, $this->now);
        $voucher->assignId(1);
        $redemption = VoucherRedemption::reserve(1, self::CLIENT, 'user-1', 'order-1', 'EUR', 2900, 290, 290, 2610, $this->now);
        $this->redemptions->save($redemption);
        $redemptionId = $redemption->id();
        \assert($redemptionId !== null);
        $this->voucherSnapshots->save(VoucherDecisionSnapshot::of($attemptId, $voucher, $redemption, $this->now));

        $result = $this->handler->handle(new CreatePaymentCommand(self::CLIENT, $attemptId));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof CreatePaymentResult);
        self::assertSame(2610, $value->amountMinor);
    }

    #[Test]
    public function isIdempotentForAnAttemptThatAlreadyHasAPayment(): void
    {
        $attemptId = $this->confirmedAttempt();
        $this->seedPricing($attemptId, 2900);

        $first = $this->handler->handle(new CreatePaymentCommand(self::CLIENT, $attemptId));
        $second = $this->handler->handle(new CreatePaymentCommand(self::CLIENT, $attemptId));

        self::assertTrue($first->isOk());
        self::assertTrue($second->isOk());
        $firstValue = $first->value();
        $secondValue = $second->value();
        \assert($firstValue instanceof CreatePaymentResult);
        \assert($secondValue instanceof CreatePaymentResult);
        self::assertSame($firstValue->paymentId, $secondValue->paymentId);
        self::assertCount(1, $this->payments->forClient(self::CLIENT));
    }

    #[Test]
    public function rejectsAnAttemptThatIsNotConfirmed(): void
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-2', self::PACKAGE, 'DE', 'EUR', PurchaseType::OneTimePayment, null, null, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);
        $attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $this->now);
        $this->attempts->save($attempt);
        $this->seedPricing($attemptId, 2900);

        $result = $this->handler->handle(new CreatePaymentCommand(self::CLIENT, $attemptId));

        self::assertTrue($result->isErr());
        self::assertSame('payment.checkout_attempt_not_confirmed', $result->error()->code);
    }

    #[Test]
    public function anUnknownCheckoutAttemptIsNotFound(): void
    {
        $result = $this->handler->handle(new CreatePaymentCommand(self::CLIENT, 999));

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.not_found', $result->error()->code);
    }

    private function confirmedAttempt(): int
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::OneTimePayment, null, null, $this->now);
        $this->attempts->save($attempt);
        $id = $attempt->id();
        \assert($id !== null);

        // Skipping ranks is allowed (Phase 18 Q3) — jump straight to Confirmed for this test.
        $attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $this->now);
        $attempt->transitionTo(CheckoutAttemptStatus::Confirmed, $this->now);
        $this->attempts->save($attempt);

        return $id;
    }

    private function seedPricing(int $attemptId, int $amountMinor): void
    {
        $price = new ResolvedPrice(self::PACKAGE, 'pro', $amountMinor, '29.00', 'EUR', PriceSource::Baseline, 'default', true, 'Pro', null, false, 0);
        $this->pricingSnapshots->save(PricingDecisionSnapshot::of($attemptId, self::CLIENT, $price, $this->now));
    }
}
