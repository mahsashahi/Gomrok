<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain;

/**
 * Persistence port for {@see ProviderTransaction}. Insert-only — an immutable
 * event log, no update method.
 */
interface ProviderTransactionRepository
{
    /**
     * @return int the new row's id
     */
    public function save(ProviderTransaction $transaction): int;

    /**
     * @return list<ProviderTransaction>
     */
    public function forAttempt(int $paymentAttemptId): array;
}
