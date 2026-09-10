<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Domain;

use Gomrok\Modules\Providers\Domain\PurchaseType;

/**
 * A `(country, purchase type)` entry in a package's country override set
 * (Phase 12 Q2). When any rows exist for a country, they **replace** the
 * package's global purchase-type set for that country; a listed type must be in
 * the global set (validated in the handler).
 */
final readonly class PackageCountryPurchaseCapability
{
    public string $countryCode;

    public function __construct(string $countryCode, public PurchaseType $purchaseType)
    {
        $this->countryCode = strtoupper(trim($countryCode));
    }
}
