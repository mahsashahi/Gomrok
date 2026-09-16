<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Vouchers;

final readonly class VoucherListItem
{
    public function __construct(
        public string $code,
        public string $name,
        public string $status,
        public string $discountLabel,
        public string $usageLabel,
        public bool $selected,
    ) {
    }
}
