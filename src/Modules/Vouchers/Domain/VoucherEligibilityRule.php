<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

/**
 * One `(dimension, value)` scoping rule for a voucher (Phase 16 Q1). `value` is
 * a country/currency code, a package or provider-account id as a string, or a
 * `PaymentMethod` / `PurchaseType` / `SubscriptionInterval` enum value.
 */
final readonly class VoucherEligibilityRule
{
    public function __construct(
        public int $voucherId,
        public VoucherEligibilityDimension $dimension,
        public string $value,
    ) {
    }
}
