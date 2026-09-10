<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

/**
 * Persistence port for {@see DefaultPackagePrice} (one row per package).
 */
interface DefaultPackagePriceRepository
{
    /** Insert or update (unique on `package_id`). */
    public function save(DefaultPackagePrice $price): void;

    public function find(int $packageId): ?DefaultPackagePrice;
}
