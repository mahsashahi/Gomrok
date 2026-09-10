<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application;

use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Providers\Domain\PaymentMethod;

/**
 * `before` / `after` payload for a `packages` audit row. No secrets are
 * involved. `metadata` is included verbatim (a client's own data — not
 * Gomrok-sensitive).
 *
 * @phpstan-type Snapshot array<string, mixed>
 */
final class PackageAuditSnapshot
{
    /**
     * @return Snapshot
     */
    public static function of(Package $package): array
    {
        return [
            'id' => $package->id(),
            'client_id' => $package->clientId(),
            'code' => $package->code()->value,
            'name' => $package->name(),
            'description' => $package->description(),
            'status' => $package->status()->value,
            'metadata' => $package->metadata(),
            'countries' => $package->countryCodes(),
            'currencies' => $package->currencyCodes(),
            'methods' => array_map(static fn (PaymentMethod $m): string => $m->value, $package->methods()),
            'provider_account_ids' => $package->providerAccountIds(),
        ];
    }
}
