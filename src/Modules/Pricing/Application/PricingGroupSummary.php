<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

/**
 * Read-only view of a {@see \Gomrok\Modules\Pricing\Domain\PricingGroup}.
 */
final readonly class PricingGroupSummary
{
    /**
     * @param list<string> $countries
     */
    public function __construct(
        public int $id,
        public int $clientId,
        public string $slug,
        public string $name,
        public int $priority,
        public ?string $deviceType,
        public string $currency,
        public bool $isDefault,
        public string $status,
        public array $countries,
    ) {
    }
}
