<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Pricing\Domain\PricingGroupPackage;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackageRepository;

final class InMemoryPricingGroupPackageRepository implements PricingGroupPackageRepository
{
    /** @var array<int, PricingGroupPackage> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(PricingGroupPackage $row): void
    {
        if ($row->id() === null) {
            $row->assignId($this->nextId++);
        }
        $id = $row->id();
        \assert($id !== null);
        $this->byId[$id] = $row;
    }

    public function find(int $pricingGroupId, int $packageId): ?PricingGroupPackage
    {
        foreach ($this->byId as $row) {
            if ($row->pricingGroupId() === $pricingGroupId && $row->packageId() === $packageId) {
                return $row;
            }
        }

        return null;
    }

    public function forGroup(int $pricingGroupId): array
    {
        $rows = array_values(array_filter($this->byId, static fn (PricingGroupPackage $r): bool => $r->pricingGroupId() === $pricingGroupId));
        usort($rows, static fn (PricingGroupPackage $a, PricingGroupPackage $b): int => [$a->displayOrder(), $a->packageId()] <=> [$b->displayOrder(), $b->packageId()]);

        return $rows;
    }
}
