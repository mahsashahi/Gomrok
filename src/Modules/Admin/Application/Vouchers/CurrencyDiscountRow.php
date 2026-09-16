<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Vouchers;

/**
 * One `voucher_currency_discounts` override row, with both a display label and
 * the raw values the "Edit override" modal needs to prefill itself.
 */
final readonly class CurrencyDiscountRow
{
    public function __construct(
        public string $currency,
        public string $discountType,
        public string $discountLabel,
        public ?string $capLabel,
        public ?int $percentBp,
        public ?int $amountMinor,
        public ?int $maxDiscountMinor,
    ) {
    }
}
