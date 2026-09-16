<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Vouchers;

final readonly class VouchersScreenResult
{
    /**
     * @param list<VoucherListItem> $vouchers
     */
    public function __construct(
        public array $vouchers,
        public ?VoucherDetail $selected,
    ) {
    }
}
