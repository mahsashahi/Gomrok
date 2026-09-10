<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

/**
 * Pricing group lifecycle — soft, reversible. A `disabled` group is skipped by
 * the resolver (its countries fall through to the next group by priority, then
 * the default).
 */
enum PricingGroupStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
