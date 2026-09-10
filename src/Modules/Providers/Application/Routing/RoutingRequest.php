<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Routing;

use Gomrok\Modules\Providers\Domain\DeviceType;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderAccountMode;
use Gomrok\Modules\Providers\Domain\PurchaseType;

/**
 * The context the router resolves a provider for. `mode` comes from the client's
 * API key prefix (`gk_live` / `gk_test`), never from the request body. `country`
 * / `currency` are assumed already validated upstream (the pricing resolver).
 */
final readonly class RoutingRequest
{
    public function __construct(
        public int $clientId,
        public string $country,
        public string $currency,
        public PurchaseType $purchaseType,
        public ProviderAccountMode $mode,
        public ?PaymentMethod $paymentMethod = null,
        public ?DeviceType $deviceType = null,
    ) {
    }
}
