<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateProviderSubscription;

final readonly class CreateProviderSubscriptionResult
{
    public function __construct(
        public int $checkoutAttemptId,
        public string $redirectUrl,
        public string $providerReference,
        public string $status,
    ) {
    }
}
