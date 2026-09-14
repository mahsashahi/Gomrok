<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateProviderCheckout;

final readonly class CreateProviderCheckoutCommand
{
    public function __construct(
        public int $clientId,
        public int $checkoutAttemptId,
        public ?string $customerEmail = null,
        public ?int $actorId = null,
    ) {
    }
}
