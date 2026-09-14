<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateProviderCheckout;

final readonly class CreateProviderCheckoutResult
{
    public function __construct(
        public int $checkoutAttemptId,
        public string $redirectUrl,
        public string $providerReference,
        public string $status,
    ) {
    }
}
