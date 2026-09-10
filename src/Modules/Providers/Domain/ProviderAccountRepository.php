<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * Persistence port for the {@see ProviderAccount} aggregate (endpoints,
 * countries, methods included). MySQL adapter in the module's `Infrastructure`.
 */
interface ProviderAccountRepository
{
    /**
     * Insert or update the account and synchronise its `provider_account_*`
     * child rows.
     */
    public function save(ProviderAccount $account): void;

    public function findById(int $id): ?ProviderAccount;

    public function findByClientAndSlug(int $clientId, string $slug): ?ProviderAccount;

    public function existsForClientWithSlug(int $clientId, string $slug): bool;

    /**
     * Reverse lookup for an inbound webhook / callback (Phase 25).
     */
    public function findByEndpointToken(string $token): ?ProviderAccount;
}
