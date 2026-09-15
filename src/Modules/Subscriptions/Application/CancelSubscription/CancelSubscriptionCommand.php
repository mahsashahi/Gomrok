<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application\CancelSubscription;

final readonly class CancelSubscriptionCommand
{
    public function __construct(
        public int $clientId,
        public int $subscriptionId,
        public ?int $actorId = null,
    ) {
    }
}
