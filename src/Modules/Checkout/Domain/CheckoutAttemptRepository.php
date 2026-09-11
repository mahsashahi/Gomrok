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
}
