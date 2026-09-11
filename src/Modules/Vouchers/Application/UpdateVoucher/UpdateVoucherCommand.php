<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\UpdateVoucher;

/**
 * Replace a voucher's mutable descriptive/default-discount fields. `code`,
 * `status`, and usage limits are changed through their own handlers.
 */
final readonly class UpdateVoucherCommand
{
    public function __construct(
        public int $clientId,
        public int $voucherId,
        public string $name,
        public ?string $description = null,
        public ?string $validFrom = null,
        public ?string $validUntil = null,
        public bool $firstPurchaseOnly = false,
        public ?int $minPurchaseMinor = null,
        public ?string $minPurchaseCurrency = null,
        public string $defaultDiscountType = 'none',
        public ?int $defaultPercentBp = null,
        public ?int $actorId = null,
    ) {
    }
}
