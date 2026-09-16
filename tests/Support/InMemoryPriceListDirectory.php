<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Pricing\Application\PriceListDirectory;
use Gomrok\Modules\Pricing\Application\PriceListSummary;
use Gomrok\Modules\Pricing\Domain\PriceList;

/**
 * A {@see PriceListDirectory} projected off {@see InMemoryPriceListRepository}
 * + {@see InMemoryPriceListPackageRepository} — mirrors {@see InMemoryPaymentDirectory}.
 */
final readonly class InMemoryPriceListDirectory implements PriceListDirectory
{
    public function __construct(
        private InMemoryPriceListRepository $lists,
        private InMemoryPriceListPackageRepository $listPackages,
    ) {
    }

    public function forGroup(int $pricingGroupId): array
    {
        return array_map(fn (PriceList $l): PriceListSummary => $this->toSummary($l), $this->lists->forGroup($pricingGroupId));
    }

    public function findById(int $priceListId): ?PriceListSummary
    {
        $list = $this->lists->findById($priceListId);

        return $list !== null ? $this->toSummary($list) : null;
    }

    private function toSummary(PriceList $list): PriceListSummary
    {
        $id = $list->id();
        \assert($id !== null);

        $packagePrices = array_map(
            static fn ($row): array => ['package_id' => $row->packageId, 'amount_minor' => $row->amountMinor, 'currency' => $row->currencyCode],
            $this->listPackages->forList($id),
        );

        return new PriceListSummary(
            $id,
            $list->clientId(),
            $list->pricingGroupId(),
            $list->name(),
            $list->isControl(),
            $list->factor(),
            $list->isEnabled(),
            $packagePrices,
        );
    }
}
