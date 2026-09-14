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

    /**
     * By id alone — used by the `GET /api/v1/payments/{id}` family (Phase
     * 24), which addresses the whole payment lifecycle (pre- and
     * post-conversion) by the stable checkout attempt id.
     */
    public function findById(int $id): ?CheckoutAttemptSummary;
}
