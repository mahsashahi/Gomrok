<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application;

/**
 * Published read API for payments (CLI, Phase 27 admin, Phase 24 payment
 * creation flow, Phase 25 webhooks).
 */
interface PaymentDirectory
{
    public function findById(int $id): ?PaymentSummary;

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?PaymentSummary;

    /**
     * @return list<PaymentSummary>
     */
    public function forClient(int $clientId): array;
}
