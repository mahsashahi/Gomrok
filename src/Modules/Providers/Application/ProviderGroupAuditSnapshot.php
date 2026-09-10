<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application;

use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupAccount;
use Gomrok\Modules\Providers\Domain\PurchaseType;

/**
 * `before` / `after` payload for a `provider_groups` audit row. No secrets are
 * involved — a group only references accounts by id.
 *
 * @phpstan-type Snapshot array<string, scalar|null|list<string>|list<array<string, scalar>>>
 */
final class ProviderGroupAuditSnapshot
{
    /**
     * @return Snapshot
     */
    public static function of(ProviderGroup $group): array
    {
        return [
            'id' => $group->id(),
            'client_id' => $group->clientId(),
            'slug' => $group->slug()->value,
            'name' => $group->name(),
            'is_default' => $group->isDefault(),
            'device_type' => $group->deviceType()?->value,
            'currency_code' => $group->currencyCode(),
            'status' => $group->status()->value,
            'countries' => $group->countryCodes(),
            'purchase_types' => array_map(static fn (PurchaseType $p): string => $p->value, $group->purchaseTypes()),
            'methods' => array_map(static fn (PaymentMethod $m): string => $m->value, $group->methods()),
            'accounts' => array_map(
                static fn (ProviderGroupAccount $a): array => [
                    'provider_account_id' => $a->providerAccountId(),
                    'priority' => $a->priority(),
                    'is_enabled' => $a->isEnabled(),
                ],
                $group->accounts(),
            ),
        ];
    }
}
