<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePriceRepository;

final class InMemoryDefaultPackagePriceRepository implements DefaultPackagePriceRepository
{
    /** @var array<int, DefaultPackagePrice> */
    private array $byPackageId = [];

    public function save(DefaultPackagePrice $price): void
    {
        $this->byPackageId[$price->packageId] = $price;
    }

    public function find(int $packageId): ?DefaultPackagePrice
    {
        return $this->byPackageId[$packageId] ?? null;
    }
}
