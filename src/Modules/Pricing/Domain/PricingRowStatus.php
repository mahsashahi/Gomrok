<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

/**
 * The pricing status of a package **within one pricing group** (Phase 13 Q3).
 *
 * - `Default`  — use `default_package_prices` (converted to the group currency if needed).
 * - `Override` — use the row's own `amount_minor` / `currency_code`.
 * - `Disabled` — the package is not sold in this group.
 */
enum PricingRowStatus: string
{
    case Default = 'default';
    case Override = 'override';
    case Disabled = 'disabled';
}
