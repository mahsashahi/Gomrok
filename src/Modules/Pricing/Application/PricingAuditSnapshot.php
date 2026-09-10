<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

use Gomrok\Modules\Pricing\Domain\ClientExchangeRate;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackage;

/**
 * `before` / `after` payloads for `pricing_*` audit rows. No secrets.
 */
final class PricingAuditSnapshot
{
    /**
     * @return array<string, scalar|null|list<string>>
     */
    public static function group(PricingGroup $group): array
    {
        return [
            'id' => $group->id(),
            'client_id' => $group->clientId(),
            'slug' => $group->slug()->value,
            'name' => $group->name(),
            'priority' => $group->priority(),
            'device_type' => $group->deviceType(),
            'currency' => $group->currencyCode(),
            'is_default' => $group->isDefault(),
            'status' => $group->status()->value,
            'countries' => $group->countryCodes(),
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    public static function groupPackage(PricingGroupPackage $row): array
    {
        return [
            'id' => $row->id(),
            'pricing_group_id' => $row->pricingGroupId(),
            'package_id' => $row->packageId(),
            'status' => $row->status()->value,
            'amount_minor' => $row->amountMinor(),
            'currency' => $row->currencyCode(),
            'name_override' => $row->nameOverride(),
            'badge_override' => $row->badgeOverride(),
            'highlighted_override' => $row->highlightedOverride(),
            'display_order' => $row->displayOrder(),
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    public static function defaultPrice(DefaultPackagePrice $price): array
    {
        return [
            'package_id' => $price->packageId,
            'amount_minor' => $price->amountMinor,
            'currency' => $price->currencyCode,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    public static function rate(ClientExchangeRate $rate): array
    {
        return [
            'client_id' => $rate->clientId,
            'base_currency' => $rate->baseCurrency,
            'quote_currency' => $rate->quoteCurrency,
            'rate' => $rate->rate,
            'effective_from' => $rate->effectiveFrom->format('Y-m-d H:i:s'),
        ];
    }
}
