<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Domain;

/**
 * Persistence port for {@see CheckoutAttempt}.
 */
interface CheckoutAttemptRepository
{
    public function save(CheckoutAttempt $attempt): void;

    public function findById(int $id): ?CheckoutAttempt;

    public function findByAttemptReference(int $clientId, string $attemptReference): ?CheckoutAttempt;

    /**
     * @return list<CheckoutAttempt>
     */
    public function forClient(int $clientId): array;

    /**
     * Non-terminal attempts (not converted, and not already an exit status)
     * whose `updated_at` is older than `$before` — abandoned checkouts with
     * no recent activity (Phase 29's checkout-abandonment sweep).
     *
     * @return list<CheckoutAttempt>
     */
    public function findStaleNonTerminal(\DateTimeImmutable $before, int $limit): array;
}
