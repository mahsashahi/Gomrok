<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;

final class InMemoryPricingGroupRepository implements PricingGroupRepository
{
    /** @var array<int, PricingGroup> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(PricingGroup $group): void
    {
        if ($group->id() === null) {
            $group->assignId($this->nextId++);
        }
        $id = $group->id();
        \assert($id !== null);
        $this->byId[$id] = $group;
    }

    public function findById(int $id): ?PricingGroup
    {
        return $this->byId[$id] ?? null;
    }

    public function findByClientAndSlug(int $clientId, string $slug): ?PricingGroup
    {
        foreach ($this->byId as $group) {
            if ($group->clientId() === $clientId && $group->slug()->value === $slug) {
                return $group;
            }
        }

        return null;
    }

    public function existsForClientWithSlug(int $clientId, string $slug): bool
    {
        return $this->findByClientAndSlug($clientId, $slug) !== null;
    }

    public function forClient(int $clientId): array
    {
        $groups = array_values(array_filter($this->byId, static fn (PricingGroup $g): bool => $g->clientId() === $clientId));
        usort(
            $groups,
            static fn (PricingGroup $a, PricingGroup $b): int => [$a->isDefault() ? 1 : 0, $a->priority(), $a->slug()->value]
                <=> [$b->isDefault() ? 1 : 0, $b->priority(), $b->slug()->value],
        );

        return $groups;
    }
}
