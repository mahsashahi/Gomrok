<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

/**
 * Persistence port for {@see PriceListPackage}.
 */
interface PriceListPackageRepository
{
    /** Insert or update (unique on `(price_list_id, package_id)`). */
    public function save(PriceListPackage $row): void;

    public function find(int $priceListId, int $packageId): ?PriceListPackage;

    public function delete(int $priceListId, int $packageId): bool;

    /**
     * @return list<PriceListPackage>
     */
    public function forList(int $priceListId): array;
}
