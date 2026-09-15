<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Domain;

interface SubscriptionEventRepository
{
    /**
     * @return int the new row's id
     */
    public function save(SubscriptionEvent $event): int;

    /**
     * @return list<SubscriptionEvent>
     */
    public function forSubscription(int $subscriptionId): array;
}
