<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\CreateVoucher;

final readonly class CreateVoucherResult
{
    public function __construct(public int $voucherId)
    {
    }
}
