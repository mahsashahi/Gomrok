<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\SelectCheckoutProvider;

final readonly class SelectCheckoutProviderResult
{
    public function __construct(
        public int $providerAccountId,
        public ?string $paymentMethod,
        public string $purchaseType,
    ) {
    }
}
