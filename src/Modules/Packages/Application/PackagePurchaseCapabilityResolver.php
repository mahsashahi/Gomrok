<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application;

use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackagePurchaseCapability;
use Gomrok\Modules\Packages\Domain\PackageRepository;

/**
 * Resolves the purchase types a package can be sold as in a given country: the
 * package's global set, **replaced** by its per-country override when any rows
 * exist for that country (Phase 12 Q2/Q4).
 *
 * This is the *package* half only — the payment-creation flow (Phase 17) then
 * intersects the result with the provider group's purchase types (Phase 10) and
 * the provider-type declaration (Phase 8). Nothing is downgraded.
 */
final readonly class PackagePurchaseCapabilityResolver
{
    public function __construct(private PackageRepository $packages)
    {
    }

    public function forId(int $packageId, ?string $country = null): PackageCapabilitySet
    {
        $package = $this->packages->findById($packageId);
        if ($package === null) {
            return PackageCapabilitySet::empty();
        }

        return $this->for($package, $country);
    }

    public function for(Package $package, ?string $country = null): PackageCapabilitySet
    {
        return new PackageCapabilitySet(array_map(
            static fn (PackagePurchaseCapability $c): ResolvedPurchaseCapability => new ResolvedPurchaseCapability(
                $c->purchaseType->value,
                $c->hasTrial,
                $c->trialDays,
                $c->durationMonths,
            ),
            $package->effectiveCapabilities($country),
        ));
    }
}
