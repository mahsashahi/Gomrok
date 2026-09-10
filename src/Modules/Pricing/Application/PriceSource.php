<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

/**
 * Where a {@see ResolvedPrice}'s amount came from.
 *
 * - `Baseline`          — `default_package_prices`, same currency as the pricing group.
 * - `Converted`         — `default_package_prices` converted to the group currency via a client rate.
 * - `GroupOverride`     — an explicit `status = override` `pricing_group_packages` row.
 * - `PriceList`         — a non-control A/B price list (Phase 15) shifted the base amount
 *                         (its `factor`, or an exact `price_list_packages` row).
 * - `DimensionOverride` — a matching `price_rules` row (Phase 14) beat the base / price-list amount.
 */
enum PriceSource: string
{
    case Baseline = 'baseline';
    case Converted = 'converted';
    case GroupOverride = 'group_override';
    case PriceList = 'price_list';
    case DimensionOverride = 'dimension_override';
}
