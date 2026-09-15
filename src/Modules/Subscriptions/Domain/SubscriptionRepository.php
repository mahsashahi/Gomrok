<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Domain;

interface SubscriptionRepository
{
    public function save(Subscription $subscription): void;

    public function findById(int $id): ?Subscription;

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?Subscription;

    /**
     * @return list<Subscription>
     */
    public function forClientUser(int $clientId, string $clientUserRef): array;
}
