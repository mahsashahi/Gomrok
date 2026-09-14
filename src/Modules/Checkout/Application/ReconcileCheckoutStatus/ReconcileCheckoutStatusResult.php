<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\ReconcileCheckoutStatus;

final readonly class ReconcileCheckoutStatusResult
{
    public function __construct(
        public int $checkoutAttemptId,
        public int $clientId,
        public string $status,
        public bool $paid,
    ) {
    }
}
