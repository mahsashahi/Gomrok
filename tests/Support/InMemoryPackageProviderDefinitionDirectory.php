<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Packages\Application\PackageProviderDefinitionDirectory;
use Gomrok\Modules\Packages\Application\PackageProviderDefinitionSummary;
use Gomrok\Modules\Packages\Domain\PackageProviderDefinition;

/**
 * A {@see PackageProviderDefinitionDirectory} projected off
 * {@see InMemoryPackageProviderDefinitionRepository} — mirrors
 * {@see InMemoryPaymentDirectory}.
 */
final readonly class InMemoryPackageProviderDefinitionDirectory implements PackageProviderDefinitionDirectory
{
    public function __construct(private InMemoryPackageProviderDefinitionRepository $definitions)
    {
    }

    public function forPackage(int $packageId): array
    {
        return array_map($this->toSummary(...), $this->definitions->forPackage($packageId));
    }

    public function find(int $packageId, int $providerAccountId): ?PackageProviderDefinitionSummary
    {
        $definition = $this->definitions->findByPackageAndAccount($packageId, $providerAccountId);

        return $definition !== null ? $this->toSummary($definition) : null;
    }

    private function toSummary(PackageProviderDefinition $definition): PackageProviderDefinitionSummary
    {
        return new PackageProviderDefinitionSummary(
            $definition->id() ?? 0,
            $definition->packageId(),
            $definition->providerAccountId(),
            $definition->providerSideName(),
            $definition->remoteId(),
            $definition->syncState()->value,
            $definition->lastError(),
        );
    }
}
