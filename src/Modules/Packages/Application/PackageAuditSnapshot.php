<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application;

use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageCountryPurchaseCapability;
use Gomrok\Modules\Packages\Domain\PackagePurchaseCapability;
use Gomrok\Modules\Providers\Domain\PaymentMethod;

/**
 * `before` / `after` payload for a `packages` audit row. No secrets are
 * involved. `metadata` is included verbatim (a client's own data — not
 * Gomrok-sensitive).
 *
 * @phpstan-type Snapshot array<string, mixed>
 */
final class PackageAuditSnapshot
{
    /**
     * @return Snapshot
     */
    public static function of(Package $package): array
    {
        return [
            'id' => $package->id(),
            'client_id' => $package->clientId(),
            'code' => $package->code()->value,
            'name' => $package->name(),
            'description' => $package->description(),
            'status' => $package->status()->value,
            'metadata' => $package->metadata(),
            'badge' => $package->badge(),
            'highlighted' => $package->highlighted(),
            'client_package_id' => $package->clientPackageId(),
            'countries' => $package->countryCodes(),
            'currencies' => $package->currencyCodes(),
            'methods' => array_map(static fn (PaymentMethod $m): string => $m->value, $package->methods()),
            'provider_account_ids' => $package->providerAccountIds(),
            'purchase_capabilities' => array_map(
                static fn (PackagePurchaseCapability $c): array => [
                    'purchase_type' => $c->purchaseType->value,
                    'has_trial' => $c->hasTrial,
                    'trial_days' => $c->trialDays,
                    'duration_months' => $c->durationMonths,
                ],
                $package->purchaseCapabilities(),
            ),
            'country_purchase_capabilities' => array_map(
                static fn (PackageCountryPurchaseCapability $o): array => [
                    'country_code' => $o->countryCode,
                    'purchase_type' => $o->purchaseType->value,
                ],
                $package->countryPurchaseCapabilities(),
            ),
        ];
    }
}
