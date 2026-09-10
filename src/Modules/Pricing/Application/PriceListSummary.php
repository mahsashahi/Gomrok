<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

/**
 * Read-only view of a {@see \Gomrok\Modules\Pricing\Domain\PriceList} plus its
 * per-package exact amounts.
 */
final readonly class PriceListSummary
{
    /**
     * @param list<array{package_id: int, amount_minor: int, currency: string}> $packagePrices
     */
    public function __construct(
        public int $id,
        public int $clientId,
        public int $pricingGroupId,
        public string $name,
        public bool $isControl,
        public string $factor,
        public bool $isEnabled,
        public array $packagePrices = [],
    ) {
    }
}
