<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\CreatePayment;

final readonly class CreatePaymentCommand
{
    public function __construct(
        public int $clientId,
        public int $checkoutAttemptId,
        public ?int $actorId = null,
    ) {
    }
}
