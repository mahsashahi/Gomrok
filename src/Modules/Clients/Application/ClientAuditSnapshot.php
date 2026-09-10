<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application;

use Gomrok\Modules\Clients\Domain\Client;

/**
 * Builds the `before` / `after` payload for a client `audit_logs` row. The
 * signing-secret key name matches the audit redactor's denylist, so it is
 * masked automatically.
 *
 * @phpstan-type Snapshot array<string, scalar|null|array<string, string>>
 */
final class ClientAuditSnapshot
{
    /**
     * @return Snapshot
     */
    public static function of(Client $client): array
    {
        $endpoints = [];
        foreach ($client->endpoints() as $endpoint) {
            $endpoints[$endpoint->purpose()->value] = $endpoint->isActive() ? $endpoint->url() : $endpoint->url() . ' (inactive)';
        }

        return [
            'id' => $client->id(),
            'slug' => (string) $client->slug(),
            'name' => $client->name(),
            'status' => $client->status()->value,
            'default_currency' => $client->defaultCurrency()->code(),
            'default_country' => $client->defaultCountry()?->value,
            'timezone' => $client->timezone(),
            'notification_signing_secret' => $client->notificationSigningSecret(),
            'disabled_reason' => $client->disabledReason(),
            'endpoints' => $endpoints,
        ];
    }
}
