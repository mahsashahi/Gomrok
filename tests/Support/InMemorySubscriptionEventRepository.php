<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Subscriptions\Domain\SubscriptionEvent;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionEventRepository;

final class InMemorySubscriptionEventRepository implements SubscriptionEventRepository
{
    /** @var array<int, SubscriptionEvent> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(SubscriptionEvent $event): int
    {
        $id = $this->nextId++;
        $this->byId[$id] = $event;

        return $id;
    }

    public function forSubscription(int $subscriptionId): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (SubscriptionEvent $e): bool => $e->subscriptionId === $subscriptionId,
        ));
    }
}
