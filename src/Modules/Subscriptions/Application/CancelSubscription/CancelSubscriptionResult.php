<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application\CancelSubscription;

final readonly class CancelSubscriptionResult
{
    public function __construct(
        public int $subscriptionId,
        public string $status,
    ) {
    }
}
