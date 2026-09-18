<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain;

/**
 * Persistence port for {@see Payment}.
 */
interface PaymentRepository
{
    public function save(Payment $payment): void;

    public function findById(int $id): ?Payment;

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?Payment;

    /**
     * @return list<Payment>
     */
    public function forClient(int $clientId): array;

    /**
     * Payments touched within a rolling window, excluding brand-new
     * `created` rows with nothing yet to compare against a provider —
     * Phase 29's payment reconciliation scan.
     *
     * @return list<Payment>
     */
    public function findRecentForReconciliation(\DateTimeImmutable $since, int $limit): array;
}
