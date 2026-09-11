<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityRule;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityRuleRepository;

final class InMemoryVoucherEligibilityRuleRepository implements VoucherEligibilityRuleRepository
{
    /** @var array<int, list<VoucherEligibilityRule>> */
    private array $byVoucher = [];

    public function replaceForVoucher(int $voucherId, array $rules): void
    {
        $this->byVoucher[$voucherId] = $rules;
    }

    public function forVoucher(int $voucherId): array
    {
        return $this->byVoucher[$voucherId] ?? [];
    }
}
