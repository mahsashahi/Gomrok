<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

use Gomrok\Modules\Pricing\Domain\PriceList;
use Gomrok\Modules\Pricing\Domain\PriceListPackage;

/**
 * `before` / `after` payloads for `price_list_*` audit rows. No secrets.
 */
final class PriceListAuditSnapshot
{
    /**
     * @return array<string, scalar|null>
     */
    public static function list(PriceList $list): array
    {
        return [
            'id' => $list->id(),
            'client_id' => $list->clientId(),
            'pricing_group_id' => $list->pricingGroupId(),
            'name' => $list->name(),
            'is_control' => $list->isControl(),
            'factor' => $list->factor(),
            'is_enabled' => $list->isEnabled(),
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    public static function listPackage(PriceListPackage $row): array
    {
        return [
            'price_list_id' => $row->priceListId,
            'package_id' => $row->packageId,
            'amount_minor' => $row->amountMinor,
            'currency' => $row->currencyCode,
        ];
    }
}
