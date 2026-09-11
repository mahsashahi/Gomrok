<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Vouchers\Domain;

use Gomrok\Modules\Vouchers\Domain\DiscountType;
use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscount;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VoucherCurrencyDiscountTest extends TestCase
{
    #[Test]
    public function fixedNeedsAPositiveAmountAndNothingElse(): void
    {
        self::assertSame('voucher_currency_discount.amount_required', VoucherCurrencyDiscount::validate(DiscountType::Fixed, null, null, null)?->code);
        self::assertSame('voucher_currency_discount.amount_required', VoucherCurrencyDiscount::validate(DiscountType::Fixed, null, 0, null)?->code);
        self::assertSame('voucher_currency_discount.unexpected_percent', VoucherCurrencyDiscount::validate(DiscountType::Fixed, 500, 500, null)?->code);
        self::assertSame('voucher_currency_discount.unexpected_cap', VoucherCurrencyDiscount::validate(DiscountType::Fixed, null, 500, 100)?->code);
        self::assertNull(VoucherCurrencyDiscount::validate(DiscountType::Fixed, null, 500, null));
    }

    #[Test]
    public function percentageNeedsBasisPointsInRange(): void
    {
        self::assertSame('voucher_currency_discount.percent_out_of_range', VoucherCurrencyDiscount::validate(DiscountType::Percentage, null, null, null)?->code);
        self::assertSame('voucher_currency_discount.percent_out_of_range', VoucherCurrencyDiscount::validate(DiscountType::Percentage, 10001, null, null)?->code);
        self::assertSame('voucher_currency_discount.unexpected_amount', VoucherCurrencyDiscount::validate(DiscountType::Percentage, 500, 500, null)?->code);
        self::assertNull(VoucherCurrencyDiscount::validate(DiscountType::Percentage, 1000, null, 800));
    }

    #[Test]
    public function fullCarriesNoValues(): void
    {
        self::assertSame('voucher_currency_discount.unexpected_value', VoucherCurrencyDiscount::validate(DiscountType::Full, 500, null, null)?->code);
        self::assertSame('voucher_currency_discount.unexpected_value', VoucherCurrencyDiscount::validate(DiscountType::Full, null, 500, null)?->code);
        self::assertNull(VoucherCurrencyDiscount::validate(DiscountType::Full, null, null, null));
    }
}
