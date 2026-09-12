<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain;

/**
 * Persistence port for {@see PaymentAttempt}.
 */
interface PaymentAttemptRepository
{
    public function save(PaymentAttempt $attempt): void;

    public function findById(int $id): ?PaymentAttempt;

    public function findLatestForPayment(int $paymentId): ?PaymentAttempt;

    public function countForPayment(int $paymentId): int;

    /**
     * @return list<PaymentAttempt>
     */
    public function forPayment(int $paymentId): array;
}
