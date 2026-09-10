<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application;

/**
 * Read-only view of a {@see \Gomrok\Modules\Packages\Domain\PackageProviderDefinition}.
 */
final readonly class PackageProviderDefinitionSummary
{
    public function __construct(
        public int $id,
        public int $packageId,
        public int $providerAccountId,
        public ?string $providerSideName,
        public ?string $remoteId,
        public string $syncState,
        public ?string $lastError,
    ) {
    }
}
