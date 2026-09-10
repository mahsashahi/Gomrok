<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

/**
 * Published read API for A/B price lists (CLI, Phase 27 admin).
 */
interface PriceListDirectory
{
    /**
     * @return list<PriceListSummary>
     */
    public function forGroup(int $pricingGroupId): array;

    public function findById(int $priceListId): ?PriceListSummary;
}
