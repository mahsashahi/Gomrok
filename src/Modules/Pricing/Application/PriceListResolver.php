<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

use Gomrok\Modules\Pricing\Domain\PriceList;
use Gomrok\Modules\Pricing\Domain\PriceListPackageRepository;
use Gomrok\Modules\Pricing\Domain\PriceListRepository;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;

/**
 * Applies the visitor's assigned A/B price list (Phase 15) to the Phase 13 base
 * price, sitting between the base amount and the Phase 14 `price_rules` step.
 *
 * Precedence for a package on a list:
 *   1. an exact {@see \Gomrok\Modules\Pricing\Domain\PriceListPackage} amount;
 *   2. else `base_amount × list.factor` (HALF_EVEN);
 *   3. else the base amount unchanged (control, or `factor = 1.0000`).
 *
 * `$priceListId` is the hook for the deferred visitor→list assignment (Phase
 * 24). Until then callers pass `null` and every resolve uses the group's control
 * list. A `$priceListId` that is unknown, from another group, or disabled falls
 * back to control (the disable-fallback behaviour).
 */
final readonly class PriceListResolver
{
    public function __construct(
        private PriceListRepository $lists,
        private PriceListPackageRepository $listPackages,
    ) {
    }

    public function apply(int $pricingGroupId, int $packageId, ?int $priceListId, ResolvedPrice $base): ResolvedPrice
    {
        $list = $this->resolveList($pricingGroupId, $priceListId);
        if ($list === null) {
            return $base;
        }

        $listId = $list->id();
        \assert($listId !== null);

        $exact = $this->listPackages->find($listId, $packageId);
        if ($exact !== null && $exact->currencyCode === $base->currencyCode) {
            $money = Money::fromMinor($exact->amountMinor, Currency::of($base->currencyCode));

            return $base->withList($exact->amountMinor, $money->amount(), PriceSource::PriceList, $listId, $list->name(), $list->factor());
        }

        if ($list->isNeutral()) {
            return $base->withList($base->amountMinor, $base->amountDecimal, $base->source, $listId, $list->name(), $list->factor());
        }

        $adjusted = Money::fromMinor($base->amountMinor, Currency::of($base->currencyCode))->multipliedBy($list->factor());

        return $base->withList($adjusted->toMinor(), $adjusted->amount(), PriceSource::PriceList, $listId, $list->name(), $list->factor());
    }

    private function resolveList(int $pricingGroupId, ?int $priceListId): ?PriceList
    {
        if ($priceListId !== null) {
            $list = $this->lists->findById($priceListId);
            if ($list !== null && $list->pricingGroupId() === $pricingGroupId && $list->isEnabled()) {
                return $list;
            }
        }

        return $this->lists->findControlForGroup($pricingGroupId);
    }
}
