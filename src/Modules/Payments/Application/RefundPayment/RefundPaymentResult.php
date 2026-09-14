<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\RefundPayment;

final readonly class RefundPaymentResult
{
    public function __construct(
        public int $paymentId,
        public string $status,
        public string $providerReference,
        public int $refundedMinor,
    ) {
    }
}
