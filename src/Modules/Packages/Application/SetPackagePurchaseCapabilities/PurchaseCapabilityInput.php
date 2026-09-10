<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities;

/**
 * One requested purchase capability for a package. `purchaseType` is a
 * `PurchaseType` value; a trial is only valid for subscription / recurring.
 */
final readonly class PurchaseCapabilityInput
{
    public function __construct(
        public string $purchaseType,
        public bool $hasTrial = false,
        public ?int $trialDays = null,
        public ?int $durationMonths = null,
    ) {
    }
}
