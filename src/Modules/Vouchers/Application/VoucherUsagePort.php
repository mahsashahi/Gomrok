<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application;

/**
 * Redemption counts backing the eligibility evaluator's usage checks. Declared
 * in Phase 16 (unimplemented seam); implemented in Phase 17 by
 * `PdoVoucherRedemptionRepository` against `voucher_redemptions`.
 *
 * "Count" for `redemptionsByUser` / `redemptionsByClient` includes both
 * `reserved` and `confirmed` rows — a reservation counts toward the cap from
 * the moment it's created (Phase 17 Q2) until it's released.
 */
interface VoucherUsagePort
{
    public function redemptionsByUser(int $voucherId, string $clientUserRef): int;

    public function redemptionsByClient(int $voucherId, int $clientId): int;

    /**
     * Count of currently `reserved` rows for the voucher — added to
     * `Voucher::redeemedCount()` (confirmed) for the global cap check.
     */
    public function activeReservations(int $voucherId): int;
}
