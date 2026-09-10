<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageRepository;

final class InMemoryPackageRepository implements PackageRepository
{
    /** @var array<int, Package> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(Package $package): void
    {
        if ($package->id() === null) {
            $package->assignId($this->nextId++);
        }

        $id = $package->id();
        \assert($id !== null);
        $this->byId[$id] = $package;
    }

    public function findById(int $id): ?Package
    {
        return $this->byId[$id] ?? null;
    }

    public function findByClientAndCode(int $clientId, string $code): ?Package
    {
        foreach ($this->byId as $package) {
            if ($package->clientId() === $clientId && $package->code()->value === $code) {
                return $package;
            }
        }

        return null;
    }

    public function existsForClientWithCode(int $clientId, string $code): bool
    {
        return $this->findByClientAndCode($clientId, $code) !== null;
    }

    public function forClient(int $clientId): array
    {
        $packages = [];
        foreach ($this->byId as $package) {
            if ($package->clientId() === $clientId) {
                $packages[] = $package;
            }
        }

        usort($packages, static fn (Package $a, Package $b): int => $a->code()->value <=> $b->code()->value);

        return $packages;
    }
}
