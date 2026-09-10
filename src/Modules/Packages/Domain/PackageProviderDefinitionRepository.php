<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Domain;

use DateTimeImmutable;

/**
 * Persistence port for {@see PackageProviderDefinition}. MySQL adapter in the
 * module's `Infrastructure`.
 */
interface PackageProviderDefinitionRepository
{
    public function save(PackageProviderDefinition $definition): void;

    public function findById(int $id): ?PackageProviderDefinition;

    public function findByPackageAndAccount(int $packageId, int $providerAccountId): ?PackageProviderDefinition;

    /**
     * @return list<PackageProviderDefinition>
     */
    public function forPackage(int $packageId): array;

    /**
     * Bulk transition: every `synced` definition of the package becomes `drift`
     * (called by the package-editing handlers, Phase 12 Q5). Returns the row
     * count changed.
     */
    public function markStaleForPackage(int $packageId, DateTimeImmutable $now): int;
}
