<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Pricing\Domain\PriceListPackage;
use Gomrok\Modules\Pricing\Domain\PriceListPackageRepository;

final class InMemoryPriceListPackageRepository implements PriceListPackageRepository
{
    /** @var array<string, PriceListPackage> */
    private array $rows = [];

    public function save(PriceListPackage $row): void
    {
        $this->rows[$row->priceListId . ':' . $row->packageId] = $row;
    }

    public function find(int $priceListId, int $packageId): ?PriceListPackage
    {
        return $this->rows[$priceListId . ':' . $packageId] ?? null;
    }

    public function delete(int $priceListId, int $packageId): bool
    {
        $key = $priceListId . ':' . $packageId;
        if (!isset($this->rows[$key])) {
            return false;
        }
        unset($this->rows[$key]);

        return true;
    }

    public function forList(int $priceListId): array
    {
        return array_values(array_filter($this->rows, static fn (PriceListPackage $r): bool => $r->priceListId === $priceListId));
    }
}
