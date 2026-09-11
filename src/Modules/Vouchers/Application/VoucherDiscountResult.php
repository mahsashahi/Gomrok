<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application;

/**
 * The output of {@see VoucherDiscountCalculator} (Phase 17 Q4). `nominal` is
 * what the discount rule says before clamping; `applied` is after clamping to
 * `[0, price]` — the two differ only when the rule's discount would otherwise
 * exceed the price. `payableMinor = priceMinor - appliedDiscountMinor`.
 */
final readonly class VoucherDiscountResult
{
    public function __construct(
        public string $currencyCode,
        public int $priceMinor,
        public int $nominalDiscountMinor,
        public int $appliedDiscountMinor,
        public int $payableMinor,
    ) {
    }
}
