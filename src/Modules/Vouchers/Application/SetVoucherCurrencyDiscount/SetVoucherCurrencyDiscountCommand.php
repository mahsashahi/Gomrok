<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\SetVoucherCurrencyDiscount;

/**
 * Upsert a per-currency discount override for a voucher (Phase 16 Q2).
 */
final readonly class SetVoucherCurrencyDiscountCommand
{
    public function __construct(
        public int $clientId,
        public int $voucherId,
        public string $currencyCode,
        public string $discountType,
        public ?int $percentBp = null,
        public ?int $amountMinor = null,
        public ?int $maxDiscountMinor = null,
        public ?int $actorId = null,
    ) {
    }
}
