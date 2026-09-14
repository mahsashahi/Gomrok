<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\RefundPayment;

final readonly class RefundPaymentCommand
{
    public function __construct(
        public int $clientId,
        public int $checkoutAttemptId,
        public ?int $amountMinor = null,
        public ?int $actorId = null,
    ) {
    }
}
