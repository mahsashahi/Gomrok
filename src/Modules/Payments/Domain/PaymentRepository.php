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
}
