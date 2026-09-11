<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption;

/**
 * `status` reflects the redemption's *current* state — on an idempotent replay
 * of an already-confirmed or already-released attempt, this is `confirmed` /
 * `released`, not `reserved`.
 */
final readonly class ReserveVoucherRedemptionResult
{
    public function __construct(
        public int $redemptionId,
        public string $status,
        public string $currencyCode,
        public int $priceMinor,
        public int $nominalDiscountMinor,
        public int $appliedDiscountMinor,
        public int $payableMinor,
    ) {
    }
}
