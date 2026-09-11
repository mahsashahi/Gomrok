<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Vouchers\Application;

use Gomrok\Modules\Vouchers\Application\ConfirmVoucherRedemption\ConfirmVoucherRedemptionHandler;
use Gomrok\Modules\Vouchers\Application\ReleaseVoucherRedemption\ReleaseVoucherRedemptionHandler;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionCommand;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionHandler;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionResult;
use Gomrok\Modules\Vouchers\Application\VoucherDiscountCalculator;
use Gomrok\Modules\Vouchers\Application\VoucherEligibilityEvaluator;
use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\RedemptionStatus;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryVoucherCurrencyDiscountRepository;
use Gomrok\Tests\Support\InMemoryVoucherEligibilityRuleRepository;
use Gomrok\Tests\Support\InMemoryVoucherRedemptionRepository;
use Gomrok\Tests\Support\InMemoryVoucherRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VoucherRedemptionHandlersTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryVoucherRepository $vouchers;
    private InMemoryVoucherRedemptionRepository $redemptions;
    private InMemoryVoucherEligibilityRuleRepository $rules;
    private InMemoryVoucherCurrencyDiscountRepository $discounts;
    private RecordingAuditLogWriter $audit;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->vouchers = new InMemoryVoucherRepository();
        $this->redemptions = new InMemoryVoucherRedemptionRepository();
        $this->rules = new InMemoryVoucherEligibilityRuleRepository();
        $this->discounts = new InMemoryVoucherCurrencyDiscountRepository();
        $this->audit = new RecordingAuditLogWriter();
        $this->clock = new FrozenClock('2026-09-11T12:00:00+00:00');
    }

    #[Test]
    public function reserveComputesTheDiscountAndIsIdempotentByAttempt(): void
    {
        $voucherId = $this->voucher(DefaultDiscountType::Percentage, 1000);
        $handler = $this->reserveHandler();

        $first = $handler->handle(new ReserveVoucherRedemptionCommand(self::CLIENT, $voucherId, 'order-1', 'EUR', 2900));
        self::assertTrue($first->isOk());
        $firstPayload = $first->value();
        self::assertInstanceOf(ReserveVoucherRedemptionResult::class, $firstPayload);
        self::assertSame('reserved', $firstPayload->status);
        self::assertSame(290, $firstPayload->appliedDiscountMinor);
        self::assertSame(2610, $firstPayload->payableMinor);

        // a retry with the same attempt reference returns the existing redemption, no re-validation
        $second = $handler->handle(new ReserveVoucherRedemptionCommand(self::CLIENT, $voucherId, 'order-1', 'EUR', 2900));
        $secondPayload = $second->value();
        self::assertInstanceOf(ReserveVoucherRedemptionResult::class, $secondPayload);
        self::assertSame($firstPayload->redemptionId, $secondPayload->redemptionId);
        self::assertCount(1, $this->redemptions->forVoucher($voucherId));
        self::assertContains('voucher_redemption.reserved', $this->audit->actions());
    }

    #[Test]
    public function reserveRejectsAnIneligibleVoucher(): void
    {
        $voucherId = $this->voucher(DefaultDiscountType::Percentage, 1000);
        $voucher = $this->vouchers->findById($voucherId);
        self::assertNotNull($voucher);
        $voucher->disable($this->clock->now());
        $this->vouchers->save($voucher);

        $result = $this->reserveHandler()->handle(new ReserveVoucherRedemptionCommand(self::CLIENT, $voucherId, 'order-1', 'EUR', 2900));

        self::assertSame('voucher.not_eligible', $result->error()->code);
        self::assertStringContainsString('voucher.disabled', (string) $result->error()->context['reasons']);
    }

    #[Test]
    public function theGlobalCapBlocksASecondConcurrentReservation(): void
    {
        $voucherId = $this->voucher(DefaultDiscountType::Full, null, maxTotal: 1);
        $handler = $this->reserveHandler();

        $first = $handler->handle(new ReserveVoucherRedemptionCommand(self::CLIENT, $voucherId, 'order-1', 'EUR', 2900));
        self::assertTrue($first->isOk());

        // a second, DIFFERENT attempt against the same exhausted voucher is rejected —
        // this is the re-check that would serialise two concurrent reserves under the
        // real FOR UPDATE lock (Phase 17 Q3); here it's proven sequentially.
        $second = $handler->handle(new ReserveVoucherRedemptionCommand(self::CLIENT, $voucherId, 'order-2', 'EUR', 2900));
        self::assertSame('voucher.not_eligible', $second->error()->code);
        self::assertStringContainsString('voucher.exhausted', (string) $second->error()->context['reasons']);
    }

    #[Test]
    public function confirmIncrementsTheGlobalCountOnceAndIsIdempotent(): void
    {
        $voucherId = $this->voucher(DefaultDiscountType::Full, null);
        $this->reserveHandler()->handle(new ReserveVoucherRedemptionCommand(self::CLIENT, $voucherId, 'order-1', 'EUR', 2900));

        $confirm = $this->confirmHandler();
        self::assertTrue($confirm->handle($voucherId, 'order-1', self::CLIENT)->isOk());
        $voucher = $this->vouchers->findById($voucherId);
        self::assertSame(1, $voucher?->redeemedCount());

        // confirming again does not double-count
        self::assertTrue($confirm->handle($voucherId, 'order-1', self::CLIENT)->isOk());
        self::assertSame(1, $this->vouchers->findById($voucherId)?->redeemedCount());

        $redemption = $this->redemptions->findByAttemptReference($voucherId, 'order-1');
        self::assertSame(RedemptionStatus::Confirmed, $redemption?->status());
        self::assertContains('voucher_redemption.confirmed', $this->audit->actions());

        self::assertSame('voucher_redemption.not_found', $confirm->handle($voucherId, 'unknown-attempt', self::CLIENT)->error()->code);
    }

    #[Test]
    public function releaseFreesTheReservationAndCannotUndoAConfirm(): void
    {
        $voucherId = $this->voucher(DefaultDiscountType::Full, null, maxTotal: 1);
        $this->reserveHandler()->handle(new ReserveVoucherRedemptionCommand(self::CLIENT, $voucherId, 'order-1', 'EUR', 2900));

        $release = $this->releaseHandler();
        self::assertTrue($release->handle($voucherId, 'order-1', self::CLIENT)->isOk());
        $redemption = $this->redemptions->findByAttemptReference($voucherId, 'order-1');
        self::assertSame(RedemptionStatus::Released, $redemption?->status());
        self::assertContains('voucher_redemption.released', $this->audit->actions());

        // a released reservation frees the cap for a new attempt
        $second = $this->reserveHandler()->handle(new ReserveVoucherRedemptionCommand(self::CLIENT, $voucherId, 'order-2', 'EUR', 2900));
        self::assertTrue($second->isOk());

        // releasing again is idempotent
        self::assertTrue($release->handle($voucherId, 'order-1', self::CLIENT)->isOk());

        // but a confirmed redemption can never be released
        $this->confirmHandler()->handle($voucherId, 'order-2', self::CLIENT);
        self::assertSame('voucher_redemption.already_confirmed', $release->handle($voucherId, 'order-2', self::CLIENT)->error()->code);
    }

    private function voucher(DefaultDiscountType $type, ?int $percentBp, ?int $maxTotal = null): int
    {
        $voucher = Voucher::create(self::CLIENT, 'V' . random_int(1000, 9999), 'V', null, null, null, false, null, null, $type, $percentBp, $this->clock->now());
        $this->vouchers->save($voucher);
        $id = $voucher->id();
        self::assertNotNull($id);
        if ($maxTotal !== null) {
            $voucher->setUsageLimits($maxTotal, null, null, $this->clock->now());
            $this->vouchers->save($voucher);
        }

        return $id;
    }

    private function reserveHandler(): ReserveVoucherRedemptionHandler
    {
        $evaluator = new VoucherEligibilityEvaluator($this->rules, $this->discounts, $this->redemptions);
        $calculator = new VoucherDiscountCalculator($this->discounts);

        return new ReserveVoucherRedemptionHandler(
            $this->vouchers,
            $this->redemptions,
            $evaluator,
            $calculator,
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );
    }

    private function confirmHandler(): ConfirmVoucherRedemptionHandler
    {
        return new ConfirmVoucherRedemptionHandler($this->vouchers, $this->redemptions, $this->audit, new SynchronousTransactions(), $this->clock);
    }

    private function releaseHandler(): ReleaseVoucherRedemptionHandler
    {
        return new ReleaseVoucherRedemptionHandler($this->vouchers, $this->redemptions, $this->audit, new SynchronousTransactions(), $this->clock);
    }
}
