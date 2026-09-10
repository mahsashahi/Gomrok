<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\SetPackageCountryPurchaseCapabilities;

/**
 * Replace a package's per-country purchase-type overrides (full replace). Each
 * entry is `country => list of PurchaseType values`; a listed type must be in
 * the package's global set. An empty map removes all overrides (every country
 * inherits the global set).
 */
final readonly class SetPackageCountryPurchaseCapabilitiesCommand
{
    /**
     * @param array<string, list<string>> $overridesByCountry
     */
    public function __construct(
        public int $packageId,
        public array $overridesByCountry,
        public ?int $actorId = null,
    ) {
    }
}
