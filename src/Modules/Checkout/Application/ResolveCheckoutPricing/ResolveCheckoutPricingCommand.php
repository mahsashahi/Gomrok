<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing;

final readonly class ResolveCheckoutPricingCommand
{
    public function __construct(
        public int $checkoutAttemptId,
        public int $clientId,
        public ?string $deviceType = null,
        public ?int $providerAccountId = null,
        public ?int $priceListId = null,
        public ?int $actorId = null,
    ) {
    }
}
