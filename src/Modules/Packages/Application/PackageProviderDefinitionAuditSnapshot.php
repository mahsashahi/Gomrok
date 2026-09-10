<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application;

use Gomrok\Modules\Packages\Domain\PackageProviderDefinition;

/**
 * `before` / `after` payload for a `package_provider_definitions` audit row.
 * `remote_id` is a provider product/plan identifier, not a secret.
 *
 * @phpstan-type Snapshot array<string, scalar|null>
 */
final class PackageProviderDefinitionAuditSnapshot
{
    /**
     * @return Snapshot
     */
    public static function of(PackageProviderDefinition $definition): array
    {
        return [
            'id' => $definition->id(),
            'package_id' => $definition->packageId(),
            'provider_account_id' => $definition->providerAccountId(),
            'provider_side_name' => $definition->providerSideName(),
            'remote_id' => $definition->remoteId(),
            'sync_state' => $definition->syncState()->value,
            'last_error' => $definition->lastError(),
        ];
    }
}
