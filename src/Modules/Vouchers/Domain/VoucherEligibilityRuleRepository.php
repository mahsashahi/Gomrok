<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

/**
 * Persistence port for {@see VoucherEligibilityRule}. Full-replace only
 * (Phase 16 Q5) — there is no incremental add/remove of a single rule.
 */
interface VoucherEligibilityRuleRepository
{
    /**
     * @param list<VoucherEligibilityRule> $rules
     */
    public function replaceForVoucher(int $voucherId, array $rules): void;

    /**
     * @return list<VoucherEligibilityRule>
     */
    public function forVoucher(int $voucherId): array;
}
