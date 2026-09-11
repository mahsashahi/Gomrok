<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application;

/**
 * Read-only view of a {@see \Gomrok\Modules\Vouchers\Domain\VoucherRedemption}.
 */
final readonly class VoucherRedemptionSummary
{
    public function __construct(
        public int $id,
        public int $voucherId,
        public ?string $clientUserRef,
        public string $attemptReference,
        public string $status,
        public string $currencyCode,
        public int $priceMinor,
        public int $nominalDiscountMinor,
        public int $appliedDiscountMinor,
        public int $payableMinor,
        public string $reservedAt,
        public ?string $confirmedAt,
        public ?string $releasedAt,
    ) {
    }
}
