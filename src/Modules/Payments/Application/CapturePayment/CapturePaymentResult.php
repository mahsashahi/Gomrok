<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\CapturePayment;

final readonly class CapturePaymentResult
{
    public function __construct(
        public int $paymentId,
        public string $status,
        public string $providerReference,
    ) {
    }
}
