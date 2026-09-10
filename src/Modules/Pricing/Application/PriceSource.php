<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

/**
 * Where a {@see ResolvedPrice}'s amount came from.
 *
 * - `Baseline`      — `default_package_prices`, same currency as the pricing group.
 * - `Converted`     — `default_package_prices` converted to the group currency via a client rate.
 * - `GroupOverride` — an explicit `status = override` `pricing_group_packages` row.
 */
enum PriceSource: string
{
    case Baseline = 'baseline';
    case Converted = 'converted';
    case GroupOverride = 'group_override';
}
