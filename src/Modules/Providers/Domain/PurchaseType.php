<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * The four purchase types Gomrok routes on (Phase 8 Q2 — a first-class concept,
 * kept separate from {@see Capability}). Country config, package availability,
 * and the payment/subscription flow all speak in these; a requested purchase
 * type is never silently downgraded.
 */
enum PurchaseType: string
{
    case OneTimePayment = 'one_time_payment';
    case RecurringPayment = 'recurring_payment';
    case AutoCharge = 'auto_charge';
    case Subscription = 'subscription';
}
