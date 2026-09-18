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

    /**
     * `active`/`trialing` subscriptions billed through a Mollie provider
     * account that have no `GatewayReferenceType::Subscription` reference
     * recorded yet — the real Mollie Subscription resource was never
     * activated (Phase 29 Q2's activation scan).
     *
     * @return list<Subscription>
     */
    public function findPendingMollieActivation(int $limit): array;

    /**
     * Subscriptions touched within a rolling window — Phase 29's
     * subscription reconciliation scan.
     *
     * @return list<Subscription>
     */
    public function findRecentForReconciliation(\DateTimeImmutable $since, int $limit): array;
}
