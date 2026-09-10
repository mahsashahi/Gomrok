<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

/**
 * Persistence port for the {@see PricingGroup} aggregate (countries included).
 */
interface PricingGroupRepository
{
    public function save(PricingGroup $group): void;

    public function findById(int $id): ?PricingGroup;

    public function findByClientAndSlug(int $clientId, string $slug): ?PricingGroup;

    public function existsForClientWithSlug(int $clientId, string $slug): bool;

    /**
     * Every group the client owns, ordered `priority ASC` then non-default before
     * default — the resolver walks this list.
     *
     * @return list<PricingGroup>
     */
    public function forClient(int $clientId): array;
}
