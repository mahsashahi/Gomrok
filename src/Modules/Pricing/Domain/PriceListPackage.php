<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

/**
 * An exact price for one package on one non-control {@see PriceList} (Phase 15
 * Q2). Overrides the list's `factor` for that package. `currencyCode` must equal
 * the list's pricing-group currency (handler-enforced).
 */
final readonly class PriceListPackage
{
    public string $currencyCode;

    public function __construct(
        public int $priceListId,
        public int $packageId,
        public int $amountMinor,
        string $currencyCode,
    ) {
        $this->currencyCode = strtoupper($currencyCode);
    }
}
