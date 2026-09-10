<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetPriceListPackagePrice;

/**
 * Pin an exact price for one package on one non-control A/B list. `currencyCode`
 * must equal the list's pricing-group currency.
 */
final readonly class SetPriceListPackagePriceCommand
{
    public function __construct(
        public int $clientId,
        public int $priceListId,
        public int $packageId,
        public int $amountMinor,
        public string $currencyCode,
        public ?int $actorId = null,
    ) {
    }
}
