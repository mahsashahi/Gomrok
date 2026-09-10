<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application;

/**
 * A purchase type a package can be sold as, with its trial / duration config,
 * after country resolution (Phase 12). Carried on {@see ResolvedPackage} and
 * returned by {@see PackagePurchaseCapabilityResolver}.
 */
final readonly class ResolvedPurchaseCapability
{
    public function __construct(
        public string $purchaseType,
        public bool $hasTrial,
        public ?int $trialDays,
        public ?int $durationMonths,
    ) {
    }
}
