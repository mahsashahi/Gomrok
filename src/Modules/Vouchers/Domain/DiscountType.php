<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

/**
 * A per-currency {@see VoucherCurrencyDiscount} override's discount kind.
 * Unlike {@see DefaultDiscountType}, `Fixed` is allowed here — an override row
 * is always pinned to one currency.
 */
enum DiscountType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
    case Full = 'full';
}
