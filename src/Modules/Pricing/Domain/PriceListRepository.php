<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

/**
 * Persistence port for {@see PriceList}.
 */
interface PriceListRepository
{
    public function save(PriceList $list): void;

    public function findById(int $id): ?PriceList;

    public function delete(int $id): bool;

    public function findControlForGroup(int $pricingGroupId): ?PriceList;

    public function existsForGroupWithName(int $pricingGroupId, string $name): bool;

    /**
     * Every list for a group, control first, then by name.
     *
     * @return list<PriceList>
     */
    public function forGroup(int $pricingGroupId): array;

    /**
     * Enabled lists for a group only — control first, then by id (stable order
     * for deterministic bucketing later).
     *
     * @return list<PriceList>
     */
    public function enabledForGroup(int $pricingGroupId): array;
}
