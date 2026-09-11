<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

/**
 * Persistence port for {@see PricingDecisionSnapshot}. Insert-only by design
 * (Phase 18 Q4) — there is deliberately no update method, so a later pricing
 * rule edit can never change a past decision's frozen record.
 */
interface PricingDecisionSnapshotRepository
{
    /**
     * @return int the new row's id
     */
    public function save(PricingDecisionSnapshot $snapshot): int;

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?PricingDecisionSnapshot;
}
