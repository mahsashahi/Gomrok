<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\RecordProviderTransaction;

final readonly class RecordProviderTransactionResult
{
    public function __construct(
        public int $paymentAttemptId,
        public int $providerTransactionId,
        public string $paymentStatus,
    ) {
    }
}
