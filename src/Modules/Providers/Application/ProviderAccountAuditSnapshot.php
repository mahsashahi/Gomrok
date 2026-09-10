<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application;

use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderAccount;

/**
 * `before` / `after` payload for a `provider_accounts` audit row. Never includes
 * the secret ciphertext or plaintext — only the display hint.
 *
 * @phpstan-type Snapshot array<string, scalar|null|list<string>>
 */
final class ProviderAccountAuditSnapshot
{
    /**
     * @return Snapshot
     */
    public static function of(ProviderAccount $account): array
    {
        return [
            'id' => $account->id(),
            'client_id' => $account->clientId(),
            'provider_type_id' => $account->providerTypeId(),
            'slug' => (string) $account->slug(),
            'name' => $account->name(),
            'mode' => $account->mode()->value,
            'status' => $account->status()->value,
            'public_key' => $account->publicKey(),
            'secret_last_four' => $account->secret()->lastFour,
            'countries' => $account->countryCodes(),
            'methods' => array_map(static fn (PaymentMethod $m): string => $m->value, $account->methods()),
            'active_endpoints' => array_values(array_map(
                static fn ($e): string => $e->kind()->value,
                array_filter($account->endpoints(), static fn ($e): bool => $e->isActive()),
            )),
        ];
    }
}
