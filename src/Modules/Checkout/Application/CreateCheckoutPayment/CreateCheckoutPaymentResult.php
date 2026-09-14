<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateCheckoutPayment;

final readonly class CreateCheckoutPaymentResult
{
    public function __construct(
        public int $checkoutAttemptId,
        public string $status,
        public string $redirectUrl,
        public string $providerReference,
    ) {
    }
}
