<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing;

final readonly class ResolveCheckoutPricingResult
{
    public function __construct(
        public int $amountMinor,
        public string $currencyCode,
        public string $source,
    ) {
    }
}
