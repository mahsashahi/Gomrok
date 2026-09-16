<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Vouchers;

final readonly class VoucherDetail
{
    /**
     * @param list<CurrencyDiscountRow>  $currencyDiscounts
     * @param list<EligibilityRuleGroup> $eligibilityGroups
     * @param array<string, string>      $eligibilityPrefill dimension → comma-joined values, for the edit modal
     * @param list<RedemptionRow>        $redemptions
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?string $description,
        public string $status,
        public bool $isActive,
        public ?string $validFrom,
        public ?string $validUntil,
        public string $validityLabel,
        public bool $firstPurchaseOnly,
        public ?int $minPurchaseMinor,
        public ?string $minPurchaseCurrency,
        public ?string $minPurchaseLabel,
        public string $defaultDiscountType,
        public ?int $defaultPercentBp,
        public string $defaultDiscountLabel,
        public ?int $maxTotalRedemptions,
        public ?int $maxPerUser,
        public ?int $maxPerClient,
        public int $redeemedCount,
        public string $usageLabel,
        public array $currencyDiscounts,
        public array $eligibilityGroups,
        public array $eligibilityPrefill,
        public array $redemptions,
    ) {
    }
}
