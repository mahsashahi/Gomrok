<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application;

/**
 * A package as it appears in a resolved catalogue list for a specific market
 * context (client + country + currency + optional method). Carries the
 * catalogue identity, the market-narrowed provider accounts / methods (Phase
 * 11), the country-effective purchase capabilities and the display fields
 * (Phase 12).
 *
 * **Still no `price`** — Phase 13.
 */
final readonly class ResolvedPackage
{
    /**
     * @param array<string, mixed>|null        $metadata
     * @param list<int>                        $availableProviderAccountIds narrowed to the client's active accounts
     * @param list<string>                     $availableMethods            `PaymentMethod` values
     * @param list<ResolvedPurchaseCapability> $purchaseCapabilities        country-effective (global, replaced by override)
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?string $description,
        public ?array $metadata,
        public ?string $badge,
        public bool $highlighted,
        public ?string $clientPackageId,
        public array $availableProviderAccountIds,
        public array $availableMethods,
        public array $purchaseCapabilities,
    ) {
    }

    /**
     * @return list<string>
     */
    public function purchaseTypes(): array
    {
        return array_map(static fn (ResolvedPurchaseCapability $c): string => $c->purchaseType, $this->purchaseCapabilities);
    }
}
