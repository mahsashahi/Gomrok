<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt;

final readonly class CreateCheckoutAttemptResult
{
    public function __construct(
        public int $checkoutAttemptId,
        public string $status,
    ) {
    }
}
