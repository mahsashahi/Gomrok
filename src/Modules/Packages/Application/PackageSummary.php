<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application;

/**
 * Read-only view of a package for other modules, the CLI and (Phase 27) the
 * admin panel. Carries the full availability sets, the global purchase types
 * (not country-resolved — see {@see PackagePurchaseCapabilityResolver}), the
 * display fields and the raw metadata. No market resolution is applied — see
 * {@see ResolvedPackage} for that.
 */
final readonly class PackageSummary
{
    /**
     * @param array<string, mixed>|null $metadata
     * @param list<string>              $countries
     * @param list<string>              $currencies
     * @param list<string>              $methods
     * @param list<int>                 $providerAccountIds
     * @param list<string>              $purchaseTypes global set, `PurchaseType` values
     */
    public function __construct(
        public int $id,
        public int $clientId,
        public string $code,
        public string $name,
        public ?string $description,
        public string $status,
        public ?array $metadata,
        public ?string $badge,
        public bool $highlighted,
        public ?string $clientPackageId,
        public array $countries,
        public array $currencies,
        public array $methods,
        public array $providerAccountIds,
        public array $purchaseTypes,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
