<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application;

/**
 * Published read API for voucher redemptions (CLI, Phase 27 admin).
 */
interface VoucherRedemptionDirectory
{
    /**
     * @return list<VoucherRedemptionSummary>
     */
    public function forVoucher(int $voucherId): array;
}
