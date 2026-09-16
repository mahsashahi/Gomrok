<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Clients;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;
use Gomrok\Modules\Clients\Domain\ApiKeyStatus;
use Gomrok\Modules\Clients\Domain\ClientApiKey;
use Gomrok\Modules\Clients\Domain\ClientApiKeyRepository;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;

/**
 * Builds the Clients screen (Phase 27 — "Clients (stat tabs + New client
 * modal)"): a flat table (not master-detail, per the design reference) with
 * three stat-tab filters (All / Live / Disabled) and a detail panel per
 * selected client covering its API keys and enabled provider accounts.
 *
 * "Environment" is a derived display value, not a domain field — `Client` has
 * no mode of its own; only each {@see ClientApiKey} carries a
 * {@see ApiKeyPrefix}. A client with any active live key reads "Live"; with
 * only active test keys, "Test"; with none, "No active keys".
 */
final readonly class ClientsScreenHandler
{
    public function __construct(
        private ClientDirectory $clients,
        private ClientApiKeyRepository $apiKeys,
        private ProviderAccountDirectory $providerAccounts,
    ) {
    }

    public function build(string $filter, ?int $selectedId): ClientsScreenResult
    {
        $all = $this->clients->all();

        $environments = [];
        foreach ($all as $client) {
            $environments[$client->id] = $this->environment($client->id);
        }

        $stats = new ClientStats(
            \count($all),
            \count(array_filter($environments, static fn (string $e): bool => $e === 'Live')),
            \count(array_filter($all, static fn (ClientSnapshot $c): bool => !$c->isActive())),
        );

        $filtered = match ($filter) {
            'live' => array_values(array_filter($all, fn (ClientSnapshot $c): bool => $environments[$c->id] === 'Live')),
            'disabled' => array_values(array_filter($all, static fn (ClientSnapshot $c): bool => !$c->isActive())),
            default => $all,
        };

        $selected = null;
        foreach ($filtered as $client) {
            if ($client->id === $selectedId) {
                $selected = $client;

                break;
            }
        }
        $selected ??= $filtered[0] ?? null;

        $rows = array_map(
            fn (ClientSnapshot $c): ClientRow => new ClientRow(
                $c->id,
                $c->slug,
                $c->name,
                $environments[$c->id],
                $this->enabledProvidersLabel($c->id),
                $c->status->value,
                $c->isActive(),
                $this->dateLabel($c->createdAt),
                $selected !== null && $c->id === $selected->id,
            ),
            $filtered,
        );

        return new ClientsScreenResult(
            $stats,
            $filter,
            $rows,
            $selected !== null ? $this->buildDetail($selected, $environments[$selected->id]) : null,
        );
    }

    private function buildDetail(ClientSnapshot $client, string $environment): ClientDetail
    {
        $keys = $this->apiKeys->findByClientId($client->id);
        usort($keys, static fn (ClientApiKey $a, ClientApiKey $b): int => $b->createdAt() <=> $a->createdAt());

        return new ClientDetail(
            $client->id,
            $client->slug,
            $client->name,
            $client->status->value,
            $client->isActive(),
            $environment,
            $client->defaultCurrency,
            $client->defaultCountry,
            $client->timezone,
            $this->dateLabel($client->createdAt),
            array_map($this->toApiKeyRow(...), $keys),
            $this->enabledProviderNames($client->id),
        );
    }

    private function toApiKeyRow(ClientApiKey $key): ApiKeyRow
    {
        return new ApiKeyRow(
            $key->keyId(),
            $key->displayToken(),
            $key->prefix()->mode(),
            $key->label(),
            $key->status()->value,
            $key->status() === ApiKeyStatus::Active,
            $this->dateLabel($key->createdAt()->format('Y-m-d H:i:s')),
            $key->lastUsedAt() !== null ? $this->dateLabel($key->lastUsedAt()->format('Y-m-d H:i:s')) : null,
            $key->expiresAt() !== null ? $this->dateLabel($key->expiresAt()->format('Y-m-d H:i:s')) : null,
        );
    }

    private function environment(int $clientId): string
    {
        $hasActiveLive = false;
        $hasActiveTest = false;
        foreach ($this->apiKeys->findByClientId($clientId) as $key) {
            if ($key->status() !== ApiKeyStatus::Active) {
                continue;
            }
            if ($key->prefix() === ApiKeyPrefix::Live) {
                $hasActiveLive = true;
            } else {
                $hasActiveTest = true;
            }
        }

        if ($hasActiveLive) {
            return 'Live';
        }

        return $hasActiveTest ? 'Test' : 'No active keys';
    }

    private function enabledProvidersLabel(int $clientId): string
    {
        $names = $this->enabledProviderNames($clientId);

        return $names === [] ? '—' : implode(', ', $names);
    }

    /**
     * @return list<string>
     */
    private function enabledProviderNames(int $clientId): array
    {
        $names = [];
        foreach ($this->providerAccounts->forClient($clientId) as $account) {
            if ($account->isActive()) {
                $names[] = $account->name;
            }
        }

        return $names;
    }

    private function dateLabel(?string $datetime): string
    {
        return $datetime !== null ? substr($datetime, 0, 10) : '—';
    }
}
