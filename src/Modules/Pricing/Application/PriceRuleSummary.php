<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

/**
 * Read-only view of a {@see \Gomrok\Modules\Pricing\Domain\PriceRule}.
 */
final readonly class PriceRuleSummary
{
    public function __construct(
        public int $id,
        public int $packageId,
        public ?int $pricingGroupId,
        public ?string $countryCode,
        public ?int $providerAccountId,
        public ?string $paymentMethod,
        public ?string $purchaseType,
        public ?string $subscriptionInterval,
        public ?string $currencyCode,
        public bool $isAvailable,
        public ?int $amountMinor,
        public int $specificity,
    ) {
    }
}
