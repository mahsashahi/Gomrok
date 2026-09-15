<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application\CreateSubscription;

final readonly class CreateSubscriptionCommand
{
    public function __construct(
        public int $clientId,
        public int $checkoutAttemptId,
        public int $paymentId,
        public ?string $subscriptionProviderReference = null,
        public ?int $actorId = null,
    ) {
    }
}
