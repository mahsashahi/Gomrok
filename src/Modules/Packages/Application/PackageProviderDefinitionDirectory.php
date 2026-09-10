<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application;

/**
 * Published read API for a package's provider-side definitions (Phase 12). The
 * admin panel (Phase 27) and the provider adapters (Phases 21–23) depend on
 * this, not the aggregate or the table.
 */
interface PackageProviderDefinitionDirectory
{
    /**
     * @return list<PackageProviderDefinitionSummary>
     */
    public function forPackage(int $packageId): array;

    public function find(int $packageId, int $providerAccountId): ?PackageProviderDefinitionSummary;
}
