<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application;

/**
 * Published read API for checkout attempts (CLI, Phase 27 admin — abandoned
 * checkout tracking).
 */
interface CheckoutAttemptDirectory
{
    /**
     * @return list<CheckoutAttemptSummary>
     */
    public function forClient(int $clientId): array;

    public function findByAttemptReference(int $clientId, string $attemptReference): ?CheckoutAttemptSummary;
}
