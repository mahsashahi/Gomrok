<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * What a provider type (stripe / mollie / paypal / ziraat) can do **in
 * principle** — before any account, client, or country config narrows it.
 * Backed by `provider_type_capabilities` / `provider_type_purchase_types`, or
 * constructed directly in tests.
 */
final readonly class ProviderTypeDeclaration
{
    /** @var array<string, PurchaseType> keyed by purchase-type value */
    private array $purchaseTypesByValue;

    /**
     * @param iterable<PurchaseType> $purchaseTypes
     */
    public function __construct(
        public string $providerTypeCode,
        iterable $purchaseTypes,
        public ProviderCapabilities $capabilities,
    ) {
        $byValue = [];
        foreach ($purchaseTypes as $purchaseType) {
            $byValue[$purchaseType->value] = $purchaseType;
        }
        $this->purchaseTypesByValue = $byValue;
    }

    public function supportsPurchaseType(PurchaseType $purchaseType): bool
    {
        return isset($this->purchaseTypesByValue[$purchaseType->value]);
    }

    /**
     * @return list<PurchaseType> in `PurchaseType` declaration order
     */
    public function purchaseTypes(): array
    {
        return array_values(array_filter(
            PurchaseType::cases(),
            fn (PurchaseType $pt): bool => isset($this->purchaseTypesByValue[$pt->value]),
        ));
    }

    public function hasCapability(Capability $capability): bool
    {
        return $this->capabilities->has($capability);
    }
}
