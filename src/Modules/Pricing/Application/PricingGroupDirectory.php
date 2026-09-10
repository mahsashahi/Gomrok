<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

/**
 * Published read API for pricing groups (CLI, Phase 27 admin).
 */
interface PricingGroupDirectory
{
    /**
     * @return list<PricingGroupSummary>
     */
    public function forClient(int $clientId): array;

    public function find(int $clientId, string $slug): ?PricingGroupSummary;
}
