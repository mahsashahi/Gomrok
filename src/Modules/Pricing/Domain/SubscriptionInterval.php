<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

/**
 * A subscription billing cadence (Phase 14 — a price-rule dimension). Reused by
 * the Subscriptions module (Phase 20). App-enforced, like `PurchaseType` — no
 * lookup table.
 */
enum SubscriptionInterval: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
}
