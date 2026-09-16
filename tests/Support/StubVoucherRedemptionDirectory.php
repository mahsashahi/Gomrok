<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Vouchers\Application\VoucherRedemptionDirectory;
use Gomrok\Modules\Vouchers\Application\VoucherRedemptionSummary;

final class StubVoucherRedemptionDirectory implements VoucherRedemptionDirectory
{
    /** @var list<VoucherRedemptionSummary> */
    private array $summaries = [];

    public function add(VoucherRedemptionSummary $summary): self
    {
        $this->summaries[] = $summary;

        return $this;
    }

    public function forVoucher(int $voucherId): array
    {
        return array_values(array_filter(
            $this->summaries,
            static fn (VoucherRedemptionSummary $r): bool => $r->voucherId === $voucherId,
        ));
    }
}
