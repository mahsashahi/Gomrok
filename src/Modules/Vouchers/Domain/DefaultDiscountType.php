<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

/**
 * A voucher's default discount (Phase 16 Q2). `Fixed` is deliberately absent —
 * a fixed amount is inherently currency-bound and only ever exists as a
 * {@see VoucherCurrencyDiscount} override row. `None` means the voucher has no
 * currency-agnostic default and discounts only in currencies that have an
 * override row.
 */
enum DefaultDiscountType: string
{
    case None = 'none';
    case Percentage = 'percentage';
    case Full = 'full';
}
