<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use DateTimeImmutable;
use Gomrok\Modules\Packages\Domain\PackageProviderDefinition;
use Gomrok\Modules\Packages\Domain\PackageProviderDefinitionRepository;
use Gomrok\Modules\Packages\Domain\PackageProviderSyncState;

final class InMemoryPackageProviderDefinitionRepository implements PackageProviderDefinitionRepository
{
    /** @var array<int, PackageProviderDefinition> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(PackageProviderDefinition $definition): void
    {
        if ($definition->id() === null) {
            $definition->assignId($this->nextId++);
        }

        $id = $definition->id();
        \assert($id !== null);
        $this->byId[$id] = $definition;
    }

    public function findById(int $id): ?PackageProviderDefinition
    {
        return $this->byId[$id] ?? null;
    }

    public function findByPackageAndAccount(int $packageId, int $providerAccountId): ?PackageProviderDefinition
    {
        foreach ($this->byId as $definition) {
            if ($definition->packageId() === $packageId && $definition->providerAccountId() === $providerAccountId) {
                return $definition;
            }
        }

        return null;
    }

    public function forPackage(int $packageId): array
    {
        $out = [];
        foreach ($this->byId as $definition) {
            if ($definition->packageId() === $packageId) {
                $out[] = $definition;
            }
        }

        return $out;
    }

    public function markStaleForPackage(int $packageId, DateTimeImmutable $now): int
    {
        $count = 0;
        foreach ($this->byId as $definition) {
            if ($definition->packageId() === $packageId && $definition->syncState() === PackageProviderSyncState::Synced) {
                $definition->markDrift($now);
                ++$count;
            }
        }

        return $count;
    }
}
