<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\CancelPayment;

final readonly class CancelPaymentResult
{
    public function __construct(
        public int $paymentId,
        public string $status,
    ) {
    }
}
