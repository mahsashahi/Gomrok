<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application;

use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderCapabilities;
use Gomrok\Modules\Providers\Domain\PurchaseType;

/**
 * The capability picture for a provider type (optionally narrowed by payment
 * method) at the point Phase 8 can resolve it — type declaration minus
 * method-level exclusions. Account and client/country narrowing layer on top in
 * Phases 9–10.
 */
final readonly class ResolvedProviderCapabilities
{
    /**
     * @param list<PurchaseType> $purchaseTypes
     */
    public function __construct(
        public string $providerTypeCode,
        public ?PaymentMethod $method,
        public array $purchaseTypes,
        public ProviderCapabilities $capabilities,
    ) {
    }

    public function supports(Capability $capability): bool
    {
        return $this->capabilities->has($capability);
    }

    public function supportsPurchaseType(PurchaseType $purchaseType): bool
    {
        foreach ($this->purchaseTypes as $supported) {
            if ($supported === $purchaseType) {
                return true;
            }
        }

        return false;
    }
}
