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

    /** @var list<int> provider account ids to treat as Mollie, for findPendingMollieActivation() */
    private array $mollieAccountIds = [];

    /** @var list<int> subscription ids to treat as already activated (has a Subscription-type gateway reference) */
    private array $activatedSubscriptionIds = [];

    public function markMollieAccount(int $providerAccountId): void
    {
        $this->mollieAccountIds[] = $providerAccountId;
    }

    public function markActivated(int $subscriptionId): void
    {
        $this->activatedSubscriptionIds[] = $subscriptionId;
    }

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

    /**
     * @return list<Subscription>
     */
    public function forClient(int $clientId): array
    {
        return array_values(array_filter($this->byId, static fn (Subscription $s): bool => $s->clientId() === $clientId));
    }

    public function findPendingMollieActivation(int $limit): array
    {
        $active = ['active', 'trialing'];
        $pending = array_values(array_filter($this->byId, function (Subscription $s) use ($active): bool {
            $id = $s->id();

            return \in_array($s->status()->value, $active, true)
                && \in_array($s->providerAccountId(), $this->mollieAccountIds, true)
                && ($id === null || !\in_array($id, $this->activatedSubscriptionIds, true));
        }));

        return \array_slice($pending, 0, $limit);
    }

    public function findRecentForReconciliation(\DateTimeImmutable $since, int $limit): array
    {
        $recent = array_values(array_filter($this->byId, static function (Subscription $s) use ($since): bool {
            $lastActivity = $s->updatedAt() ?? $s->createdAt();

            return $lastActivity >= $since;
        }));
        usort($recent, static function (Subscription $a, Subscription $b): int {
            $aTime = $a->updatedAt() ?? $a->createdAt();
            $bTime = $b->updatedAt() ?? $b->createdAt();

            return $aTime <=> $bTime;
        });

        return \array_slice($recent, 0, $limit);
    }
}
