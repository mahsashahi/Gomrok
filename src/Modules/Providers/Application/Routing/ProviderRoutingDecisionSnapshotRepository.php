<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Routing;

/**
 * Persistence port for {@see ProviderRoutingDecisionSnapshot}. Insert-only
 * (Phase 18 Q4).
 */
interface ProviderRoutingDecisionSnapshotRepository
{
    /**
     * @return int the new row's id
     */
    public function save(ProviderRoutingDecisionSnapshot $snapshot): int;

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?ProviderRoutingDecisionSnapshot;
}
