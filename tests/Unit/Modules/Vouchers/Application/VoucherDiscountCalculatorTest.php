<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Vouchers\Application;

use DateTimeImmutable;
use Gomrok\Modules\Vouchers\Application\VoucherDiscountCalculator;
use Gomrok\Modules\Vouchers\Application\VoucherDiscountResult;
use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\DiscountType;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscount;
use Gomrok\Tests\Support\InMemoryVoucherCurrencyDiscountRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VoucherDiscountCalculatorTest extends TestCase
{
    private const VOUCHER_ID = 1;

    private InMemoryVoucherCurrencyDiscountRepository $overrides;
    private VoucherDiscountCalculator $calculator;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-11T12:00:00+00:00');
        $this->overrides = new InMemoryVoucherCurrencyDiscountRepository();
        $this->calculator = new VoucherDiscountCalculator($this->overrides);
    }

    #[Test]
    public function percentageDiscountRoundsHalfEven(): void
    {
        $voucher = $this->voucher(DefaultDiscountType::Percentage, 1000); // 10.00%

        $result = $this->ok($this->calculator->calculate($voucher, 'EUR', 2900));

        self::assertSame(2900, $result->priceMinor);
        self::assertSame(290, $result->nominalDiscountMinor);
        self::assertSame(290, $result->appliedDiscountMinor);
        self::assertSame(2610, $result->payableMinor);
    }

    #[Test]
    public function percentageDiscountRespectsTheCap(): void
    {
        $voucher = $this->voucher(DefaultDiscountType::Percentage, 5000); // 50.00%
        $this->overrides->save(new VoucherCurrencyDiscount(self::VOUCHER_ID, 'EUR', DiscountType::Percentage, 5000, null, 500));

        $result = $this->ok($this->calculator->calculate($voucher, 'EUR', 2900));

        // 50% of 2900 = 1450, but capped at 500
        self::assertSame(1450, $result->nominalDiscountMinor);
        self::assertSame(500, $result->appliedDiscountMinor);
        self::assertSame(2400, $result->payableMinor);
    }

    #[Test]
    public function fullDiscountZerosThePayableAmount(): void
    {
        $voucher = $this->voucher(DefaultDiscountType::Full, null);

        $result = $this->ok($this->calculator->calculate($voucher, 'EUR', 2900));

        self::assertSame(2900, $result->appliedDiscountMinor);
        self::assertSame(0, $result->payableMinor);
    }

    #[Test]
    public function aFixedOverrideBeatsTheDefaultAndClampsToThePrice(): void
    {
        $voucher = $this->voucher(DefaultDiscountType::Percentage, 1000);
        $this->overrides->save(new VoucherCurrencyDiscount(self::VOUCHER_ID, 'TRY', DiscountType::Fixed, null, 5000, null));

        // a fixed 50.00 TRY override on a smaller 30.00 TRY price clamps, never negative
        $result = $this->ok($this->calculator->calculate($voucher, 'TRY', 3000));

        self::assertSame(5000, $result->nominalDiscountMinor); // what the rule says
        self::assertSame(3000, $result->appliedDiscountMinor); // clamped to the price
        self::assertSame(0, $result->payableMinor);
    }

    #[Test]
    public function aNoneDefaultWithNoOverrideIsAnError(): void
    {
        $voucher = $this->voucher(DefaultDiscountType::None, null);

        $result = $this->calculator->calculate($voucher, 'GBP', 1000);

        self::assertTrue($result->isErr());
        self::assertSame('voucher.no_discount_for_currency', $result->error()->code);
    }

    private function voucher(DefaultDiscountType $type, ?int $percentBp): Voucher
    {
        $voucher = Voucher::create(7, 'TEST', 'Test', null, null, null, false, null, null, $type, $percentBp, $this->now);
        $voucher->assignId(self::VOUCHER_ID);

        return $voucher;
    }

    private function ok(\Gomrok\Shared\Domain\Result $result): VoucherDiscountResult
    {
        self::assertTrue($result->isOk(), $result->isErr() ? $result->error()->code : '');
        $value = $result->value();
        self::assertInstanceOf(VoucherDiscountResult::class, $value);

        return $value;
    }
}
