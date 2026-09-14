<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\CancelPayment;

final readonly class CancelPaymentCommand
{
    public function __construct(
        public int $clientId,
        public int $checkoutAttemptId,
        public ?int $actorId = null,
    ) {
    }
}
