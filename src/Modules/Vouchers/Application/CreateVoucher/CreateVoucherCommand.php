<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\CreateVoucher;

/**
 * Create a voucher with its default discount. Eligibility rules, currency
 * overrides, and usage limits are set separately (Phase 16 Q5).
 */
final readonly class CreateVoucherCommand
{
    public function __construct(
        public int $clientId,
        public string $code,
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
