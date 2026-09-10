<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Packages\Application\PackageSummary;

/**
 * In-memory {@see PackageDirectory} for pricing / catalogue tests.
 */
final class StubPackageDirectory implements PackageDirectory
{
    /** @var list<PackageSummary> */
    private array $packages = [];

    /**
     * @param list<string> $purchaseTypes
     */
    public function add(
        int $id,
        int $clientId,
        string $code,
        ?string $name = null,
        string $status = 'active',
        ?string $badge = null,
        bool $highlighted = false,
        array $purchaseTypes = ['one_time_payment'],
    ): self {
        $this->packages[] = new PackageSummary(
            $id,
            $clientId,
            $code,
            $name ?? ucfirst($code),
            null,
            $status,
            null,
            $badge,
            $highlighted,
            null,
            [],
            [],
            [],
            [],
            $purchaseTypes,
        );

        return $this;
    }

    public function findById(int $id): ?PackageSummary
    {
        foreach ($this->packages as $package) {
            if ($package->id === $id) {
                return $package;
            }
        }

        return null;
    }

    public function find(int $clientId, string $code): ?PackageSummary
    {
        foreach ($this->packages as $package) {
            if ($package->clientId === $clientId && $package->code === $code) {
                return $package;
            }
        }

        return null;
    }

    public function forClient(int $clientId): array
    {
        return array_values(array_filter($this->packages, static fn (PackageSummary $p): bool => $p->clientId === $clientId));
    }
}
