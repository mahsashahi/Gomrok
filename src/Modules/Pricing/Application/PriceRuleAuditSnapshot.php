<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

use Gomrok\Modules\Pricing\Domain\PriceRule;

/**
 * `before` / `after` payload for a `price_rules` audit row.
 *
 * @phpstan-type Snapshot array<string, scalar|null>
 */
final class PriceRuleAuditSnapshot
{
    /**
     * @return Snapshot
     */
    public static function of(PriceRule $rule): array
    {
        return [
            'id' => $rule->id(),
            'client_id' => $rule->clientId(),
            'package_id' => $rule->packageId(),
            'pricing_group_id' => $rule->pricingGroupId(),
            'country_code' => $rule->countryCode(),
            'provider_account_id' => $rule->providerAccountId(),
            'payment_method' => $rule->paymentMethod()?->value,
            'purchase_type' => $rule->purchaseType()?->value,
            'subscription_interval' => $rule->subscriptionInterval()?->value,
            'currency_code' => $rule->currencyCode(),
            'is_available' => $rule->isAvailable(),
            'amount_minor' => $rule->amountMinor(),
        ];
    }
}
