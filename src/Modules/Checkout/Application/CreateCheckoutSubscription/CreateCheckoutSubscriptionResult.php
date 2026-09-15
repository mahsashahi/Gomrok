<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateCheckoutSubscription;

final readonly class CreateCheckoutSubscriptionResult
{
    public function __construct(
        public int $checkoutAttemptId,
        public string $status,
        public string $redirectUrl,
        public string $providerReference,
    ) {
    }
}
