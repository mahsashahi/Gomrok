<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

/**
 * Persistence port for {@see VoucherDecisionSnapshot}. Insert-only (Phase 18 Q4).
 */
interface VoucherDecisionSnapshotRepository
{
    /**
     * @return int the new row's id
     */
    public function save(VoucherDecisionSnapshot $snapshot): int;

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?VoucherDecisionSnapshot;
}
