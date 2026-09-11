<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\SetVoucherCurrencyDiscount;

final readonly class SetVoucherCurrencyDiscountResult
{
    public function __construct(public bool $created)
    {
    }
}
