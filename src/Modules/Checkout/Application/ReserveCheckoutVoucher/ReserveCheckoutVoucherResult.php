<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher;

final readonly class ReserveCheckoutVoucherResult
{
    public function __construct(
        public int $voucherRedemptionId,
        public int $payableMinor,
    ) {
    }
}
