<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Providers;

/**
 * One provider account in a routing group's priority chain, plus this
 * screen's "resolved-provider readout" (Phase 27 scope). `resolutionStatus`
 * applies only the two context-free checks {@see \Gomrok\Modules\Providers\Application\Routing\ProviderRouter}
 * runs before anything request-specific (link enabled, account active) — a
 * static admin screen has no country/purchase-type/method to simulate the
 * rest of the router's filtering with.
 */
final readonly class GroupAccountRow
{
    public function __construct(
        public int $providerAccountId,
        public string $slug,
        public string $name,
        public string $providerTypeCode,
        public string $mode,
        public int $priority,
        public bool $isEnabledLink,
        public bool $accountIsActive,
        public string $resolutionStatus,
        public string $resolutionLabel,
    ) {
    }
}
