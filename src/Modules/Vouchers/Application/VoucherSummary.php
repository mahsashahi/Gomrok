<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application;

/**
 * Read-only view of a {@see \Gomrok\Modules\Vouchers\Domain\Voucher} plus its
 * eligibility rules and currency-discount overrides.
 */
final readonly class VoucherSummary
{
    /**
     * @param list<array{dimension: string, value: string}> $eligibilityRules
     * @param list<array{currency: string, discount_type: string, percent_bp: ?int, amount_minor: ?int, max_discount_minor: ?int}> $currencyDiscounts
     */
    public function __construct(
        public int $id,
        public int $clientId,
        public string $code,
        public string $name,
        public ?string $description,
        public string $status,
        public ?string $validFrom,
        public ?string $validUntil,
        public bool $firstPurchaseOnly,
        public ?int $minPurchaseMinor,
        public ?string $minPurchaseCurrency,
        public string $defaultDiscountType,
        public ?int $defaultPercentBp,
        public ?int $maxTotalRedemptions,
        public ?int $maxPerUser,
        public ?int $maxPerClient,
        public int $redeemedCount,
        public array $eligibilityRules = [],
        public array $currencyDiscounts = [],
    ) {
    }
}
