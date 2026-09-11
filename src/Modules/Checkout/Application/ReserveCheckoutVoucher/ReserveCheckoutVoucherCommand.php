<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher;

final readonly class ReserveCheckoutVoucherCommand
{
    public function __construct(
        public int $checkoutAttemptId,
        public int $clientId,
        public string $voucherCode,
        public ?string $clientUserRef = null,
        public ?bool $isFirstPurchase = null,
        public ?int $actorId = null,
    ) {
    }
}
