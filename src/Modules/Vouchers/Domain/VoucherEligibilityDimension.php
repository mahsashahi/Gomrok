<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

/**
 * A {@see VoucherEligibilityRule} dimension (Phase 16 Q1). Several rules for the
 * same dimension are OR'd; across dimensions they're AND'd; no rules for a
 * dimension means that dimension is unrestricted.
 */
enum VoucherEligibilityDimension: string
{
    case Country = 'country';
    case Currency = 'currency';
    case Package = 'package';
    case ProviderAccount = 'provider_account';
    case PaymentMethod = 'payment_method';
    case PurchaseType = 'purchase_type';
    case SubscriptionInterval = 'subscription_interval';
}
