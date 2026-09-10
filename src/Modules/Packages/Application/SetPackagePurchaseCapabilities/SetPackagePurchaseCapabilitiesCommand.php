<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities;

/**
 * Replace a package's global purchase-type set (full replace). An empty list
 * makes the package not sellable (fail closed).
 */
final readonly class SetPackagePurchaseCapabilitiesCommand
{
    /**
     * @param list<PurchaseCapabilityInput> $capabilities
     */
    public function __construct(
        public int $packageId,
        public array $capabilities,
        public ?int $actorId = null,
    ) {
    }
}
