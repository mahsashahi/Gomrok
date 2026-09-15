<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application\CreateSubscription;

final readonly class CreateSubscriptionResult
{
    public function __construct(
        public int $subscriptionId,
        public string $status,
    ) {
    }
}
