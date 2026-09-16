<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application;

/**
 * Published read API for subscriptions (CLI, Phase 27 admin, HTTP). Other
 * modules depend on this; never on the aggregate or the tables.
 *
 * `forClientUser()` is CLAUDE.md's Subscription Ownership Model ownership
 * query answered directly: "given a client user ID, which active
 * subscriptions does the user have?"
 */
interface SubscriptionDirectory
{
    public function findById(int $id): ?SubscriptionSummary;

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?SubscriptionSummary;

    /**
     * @return list<SubscriptionSummary>
     */
    public function forClientUser(int $clientId, string $clientUserRef): array;

    /**
     * Every subscription for a client, regardless of owning user — the admin
     * panel's Home screen (Phase 27) needs "how many active subscriptions
     * does this client have," not one user's.
     *
     * @return list<SubscriptionSummary>
     */
    public function forClient(int $clientId): array;
}
