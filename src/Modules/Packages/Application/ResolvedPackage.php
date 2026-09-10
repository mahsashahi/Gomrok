<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application;

/**
 * A package as it appears in a resolved catalogue list for a specific market
 * context (client + country + currency + optional method). Phase 11 carries the
 * catalogue identity and the market-narrowed provider accounts / methods.
 *
 * **No `price`** (Phase 13) and **no `purchaseTypes`** (Phase 12) yet — those
 * fields are added when their modules land.
 */
final readonly class ResolvedPackage
{
    /**
     * @param array<string, mixed>|null $metadata
     * @param list<int>                 $availableProviderAccountIds narrowed to the client's active accounts
     * @param list<string>              $availableMethods            `PaymentMethod` values
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?string $description,
        public ?array $metadata,
        public array $availableProviderAccountIds,
        public array $availableMethods,
    ) {
    }
}
