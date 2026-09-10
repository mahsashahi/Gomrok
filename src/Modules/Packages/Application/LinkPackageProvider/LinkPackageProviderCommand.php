<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\LinkPackageProvider;

/**
 * Create or update the `package_provider_definitions` row for one
 * `(package, provider account)` pair. Passing `remoteId` marks it `synced`;
 * omitting it leaves / sets `not_created`.
 */
final readonly class LinkPackageProviderCommand
{
    public function __construct(
        public int $packageId,
        public int $providerAccountId,
        public ?string $providerSideName = null,
        public ?string $remoteId = null,
        public ?int $actorId = null,
    ) {
    }
}
