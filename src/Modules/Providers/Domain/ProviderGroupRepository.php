<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * Persistence port for the {@see ProviderGroup} aggregate (countries, ordered
 * accounts, purchase types and methods included). MySQL adapter in the module's
 * `Infrastructure`.
 */
interface ProviderGroupRepository
{
    /**
     * Insert or update the group and synchronise its `provider_group_*` child
     * rows. Callers wrap this in a transaction.
     */
    public function save(ProviderGroup $group): void;

    public function findById(int $id): ?ProviderGroup;

    public function findByClientAndSlug(int $clientId, string $slug): ?ProviderGroup;

    public function existsForClientWithSlug(int $clientId, string $slug): bool;

    /**
     * Every group the client owns (active and disabled), so the router can
     * resolve the market itself. Ordered: non-default groups first, then the
     * default, by slug.
     *
     * @return list<ProviderGroup>
     */
    public function forClient(int $clientId): array;
}
