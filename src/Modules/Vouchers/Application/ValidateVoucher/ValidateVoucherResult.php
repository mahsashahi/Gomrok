<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\ValidateVoucher;

/**
 * The outcome of {@see ValidateVoucherHandler}. `nominalDiscountMinor` /
 * `appliedDiscountMinor` / `payableMinor` are `null` whenever `eligible` is
 * `false` — an ineligible voucher has no discount to preview, only reasons.
 *
 * @see \Gomrok\Modules\Vouchers\Application\VoucherEligibility for the reason codes
 */
final readonly class ValidateVoucherResult
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public bool $eligible,
        public array $reasons,
        public string $voucherCode,
        public string $voucherName,
        public int $priceAmountMinor,
        public string $priceAmountDecimal,
        public string $currencyCode,
        public ?int $nominalDiscountMinor,
        public ?int $appliedDiscountMinor,
        public ?int $payableMinor,
    ) {
    }
}
