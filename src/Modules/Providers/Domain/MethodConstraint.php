<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * The capabilities and purchase types a given payment method removes from a
 * provider type's declaration (e.g. Mollie via PayPal loses subscriptions).
 * Produced by {@see MethodCapabilityRules}.
 */
final readonly class MethodConstraint
{
    /**
     * @param list<Capability>   $excludedCapabilities
     * @param list<PurchaseType> $excludedPurchaseTypes
     */
    public function __construct(
        public array $excludedCapabilities = [],
        public array $excludedPurchaseTypes = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->excludedCapabilities === [] && $this->excludedPurchaseTypes === [];
    }
}
