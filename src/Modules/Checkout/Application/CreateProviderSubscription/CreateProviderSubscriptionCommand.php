<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateProviderSubscription;

final readonly class CreateProviderSubscriptionCommand
{
    public function __construct(
        public int $clientId,
        public int $checkoutAttemptId,
        public ?string $customerEmail = null,
        public ?int $actorId = null,
    ) {
    }
}
