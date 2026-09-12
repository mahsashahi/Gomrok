<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\CreatePayment;

final readonly class CreatePaymentResult
{
    public function __construct(
        public int $paymentId,
        public string $status,
        public int $amountMinor,
        public string $currencyCode,
    ) {
    }
}
