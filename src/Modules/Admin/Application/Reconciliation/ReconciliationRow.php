<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Reconciliation;

final readonly class ReconciliationRow
{
    public function __construct(
        public int $id,
        public string $clientLabel,
        public string $targetType,
        public int $targetId,
        public string $localStatus,
        public string $providerStatusRaw,
        public ?string $mappedProviderStatus,
        public string $detectedLabel,
        public bool $isResolved,
        public ?string $resolvedLabel,
        public ?string $resolvedByLabel,
    ) {
    }
}
