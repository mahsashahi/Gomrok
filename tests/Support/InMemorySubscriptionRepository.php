<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionRepository;

final class InMemorySubscriptionRepository implements SubscriptionRepository
{
    /** @var array<int, Subscription> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(Subscription $subscription): void
    {
        if ($subscription->id() === null) {
            $subscription->assignId($this->nextId++);
        }
        $id = $subscription->id();
        \assert($id !== null);
        $this->byId[$id] = $subscription;
    }

    public function findById(int $id): ?Subscription
    {
        return $this->byId[$id] ?? null;
    }

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?Subscription
    {
        foreach ($this->byId as $subscription) {
            if ($subscription->checkoutAttemptId() === $checkoutAttemptId) {
                return $subscription;
            }
        }

        return null;
    }

    public function forClientUser(int $clientId, string $clientUserRef): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (Subscription $s): bool => $s->clientId() === $clientId && $s->clientUserRef() === $clientUserRef,
        ));
    }
}
