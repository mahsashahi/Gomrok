<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Sales;

/**
 * One provider transaction under a payment's expandable event timeline
 * (Phase 27) — a flattened view of {@see \Gomrok\Modules\Payments\Domain\ProviderTransaction}.
 */
final readonly class SalesTimelineEntry
{
    public function __construct(
        public string $kind,
        public string $providerStatusRaw,
        public string $createdAt,
    ) {
    }
}
