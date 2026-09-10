<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupRepository;

final class InMemoryProviderGroupRepository implements ProviderGroupRepository
{
    /** @var array<int, ProviderGroup> */
    private array $byId = [];

    private int $nextId = 1;
    private int $nextChildId = 1;

    public function save(ProviderGroup $group): void
    {
        if ($group->id() === null) {
            $group->assignId($this->nextId++);
        }

        foreach ($group->accounts() as $entry) {
            if ($entry->id() === null) {
                $entry->assignId($this->nextChildId++);
            }
        }

        $id = $group->id();
        \assert($id !== null);
        $this->byId[$id] = $group;
    }

    public function findById(int $id): ?ProviderGroup
    {
        return $this->byId[$id] ?? null;
    }

    public function findByClientAndSlug(int $clientId, string $slug): ?ProviderGroup
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
        $groups = [];
        foreach ($this->byId as $group) {
            if ($group->clientId() === $clientId) {
                $groups[] = $group;
            }
        }

        usort($groups, static function (ProviderGroup $a, ProviderGroup $b): int {
            return [$a->isDefault() ? 1 : 0, $a->slug()->value] <=> [$b->isDefault() ? 1 : 0, $b->slug()->value];
        });

        return $groups;
    }
}
