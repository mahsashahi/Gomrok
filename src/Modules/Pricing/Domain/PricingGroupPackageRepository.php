<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

/**
 * Persistence port for {@see PricingGroupPackage} rows.
 */
interface PricingGroupPackageRepository
{
    public function save(PricingGroupPackage $row): void;

    public function find(int $pricingGroupId, int $packageId): ?PricingGroupPackage;

    /**
     * All explicit rows for a group, ordered by `display_order` then `package_id`.
     *
     * @return list<PricingGroupPackage>
     */
    public function forGroup(int $pricingGroupId): array;
}
