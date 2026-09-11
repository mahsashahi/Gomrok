<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application;

/**
 * Per-user / per-client redemption counts (Phase 16 Q4 — declared here as the
 * seam for **Phase 17**, which owns `voucher_redemptions` and implements this
 * port; {@see VoucherEligibilityEvaluator} does not call it yet, so no adapter
 * is bound in Phase 16).
 */
interface VoucherUsagePort
{
    public function redemptionsByUser(int $voucherId, string $clientUserRef): int;

    public function redemptionsByClient(int $voucherId, int $clientId): int;
}
