<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

/**
 * Published read API for price rules (CLI, Phase 27 admin).
 */
interface PriceRuleDirectory
{
    /**
     * @return list<PriceRuleSummary>
     */
    public function forClientPackage(int $clientId, int $packageId): array;
}
