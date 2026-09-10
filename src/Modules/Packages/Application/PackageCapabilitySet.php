<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application;

use Gomrok\Modules\Providers\Domain\PurchaseType;

/**
 * The purchase types a package can be sold as in a resolved country context
 * (Phase 12) — before the payment flow intersects them with the provider group
 * and provider-type declaration.
 */
final readonly class PackageCapabilitySet
{
    /**
     * @param list<ResolvedPurchaseCapability> $capabilities
     */
    public function __construct(public array $capabilities)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->capabilities === [];
    }

    public function supports(PurchaseType $purchaseType): bool
    {
        foreach ($this->capabilities as $capability) {
            if ($capability->purchaseType === $purchaseType->value) {
                return true;
            }
        }

        return false;
    }

    public function for(PurchaseType $purchaseType): ?ResolvedPurchaseCapability
    {
        foreach ($this->capabilities as $capability) {
            if ($capability->purchaseType === $purchaseType->value) {
                return $capability;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(static fn (ResolvedPurchaseCapability $c): string => $c->purchaseType, $this->capabilities);
    }
}
