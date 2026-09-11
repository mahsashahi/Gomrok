<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Vouchers\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VoucherTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
    }

    #[Test]
    public function createNormalisesCodeAndTrimsFields(): void
    {
        $voucher = Voucher::create(7, ' welcome10 ', ' Welcome ', ' ', null, null, false, null, null, DefaultDiscountType::Percentage, 1000, $this->now);

        self::assertSame('WELCOME10', $voucher->code());
        self::assertSame('Welcome', $voucher->name());
        self::assertNull($voucher->description());
        self::assertTrue($voucher->isActive());
        self::assertSame(0, $voucher->redeemedCount());
    }

    #[Test]
    public function validateDefaultDiscountRejectsInconsistentValues(): void
    {
        self::assertSame('voucher.percent_out_of_range', Voucher::validateDefaultDiscount(DefaultDiscountType::Percentage, null)?->code);
        self::assertSame('voucher.percent_out_of_range', Voucher::validateDefaultDiscount(DefaultDiscountType::Percentage, 0)?->code);
        self::assertSame('voucher.percent_out_of_range', Voucher::validateDefaultDiscount(DefaultDiscountType::Percentage, 10001)?->code);
        self::assertSame('voucher.unexpected_percent', Voucher::validateDefaultDiscount(DefaultDiscountType::None, 500)?->code);
        self::assertSame('voucher.unexpected_percent', Voucher::validateDefaultDiscount(DefaultDiscountType::Full, 500)?->code);

        self::assertNull(Voucher::validateDefaultDiscount(DefaultDiscountType::Percentage, 500));
        self::assertNull(Voucher::validateDefaultDiscount(DefaultDiscountType::None, null));
        self::assertNull(Voucher::validateDefaultDiscount(DefaultDiscountType::Full, null));
    }

    #[Test]
    public function validateMinPurchaseRequiresBothOrNeither(): void
    {
        self::assertSame('voucher.min_purchase_incomplete', Voucher::validateMinPurchase(1000, null)?->code);
        self::assertSame('voucher.min_purchase_incomplete', Voucher::validateMinPurchase(null, 'EUR')?->code);
        self::assertSame('voucher.min_purchase_non_positive', Voucher::validateMinPurchase(0, 'EUR')?->code);
        self::assertNull(Voucher::validateMinPurchase(null, null));
        self::assertNull(Voucher::validateMinPurchase(1000, 'EUR'));
    }

    #[Test]
    public function validateWindowRejectsFromAfterUntil(): void
    {
        $from = new DateTimeImmutable('2026-02-01');
        $until = new DateTimeImmutable('2026-01-01');

        self::assertSame('voucher.invalid_window', Voucher::validateWindow($from, $until)?->code);
        self::assertNull(Voucher::validateWindow($until, $from));
        self::assertNull(Voucher::validateWindow(null, null));
    }

    #[Test]
    public function setUsageLimitsRejectsZeroAndNegative(): void
    {
        $voucher = Voucher::create(7, 'X', 'X', null, null, null, false, null, null, DefaultDiscountType::None, null, $this->now);

        self::assertSame('voucher.invalid_limit', $voucher->setUsageLimits(0, null, null, $this->now)?->code);
        self::assertSame('voucher.invalid_limit', $voucher->setUsageLimits(null, -1, null, $this->now)?->code);

        // the canonical example: valid for everyone, once per user
        self::assertNull($voucher->setUsageLimits(null, 1, null, $this->now));
        self::assertNull($voucher->maxTotalRedemptions());
        self::assertSame(1, $voucher->maxPerUser());
        self::assertNull($voucher->maxPerClient());
    }

    #[Test]
    public function enableAndDisableAreIdempotent(): void
    {
        $voucher = Voucher::create(7, 'X', 'X', null, null, null, false, null, null, DefaultDiscountType::None, null, $this->now);

        self::assertTrue($voucher->isActive());
        $voucher->disable($this->now);
        self::assertFalse($voucher->isActive());
        $voucher->disable($this->now);
        self::assertFalse($voucher->isActive());
        $voucher->enable($this->now);
        self::assertTrue($voucher->isActive());
    }
}
