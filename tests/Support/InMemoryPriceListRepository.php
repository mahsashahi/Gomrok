<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Pricing\Domain\PriceList;
use Gomrok\Modules\Pricing\Domain\PriceListRepository;

final class InMemoryPriceListRepository implements PriceListRepository
{
    /** @var array<int, PriceList> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(PriceList $list): void
    {
        if ($list->id() === null) {
            $list->assignId($this->nextId++);
        }
        $id = $list->id();
        \assert($id !== null);
        $this->byId[$id] = $list;
    }

    public function findById(int $id): ?PriceList
    {
        return $this->byId[$id] ?? null;
    }

    public function delete(int $id): bool
    {
        if (!isset($this->byId[$id])) {
            return false;
        }
        unset($this->byId[$id]);

        return true;
    }

    public function findControlForGroup(int $pricingGroupId): ?PriceList
    {
        foreach ($this->byId as $list) {
            if ($list->pricingGroupId() === $pricingGroupId && $list->isControl()) {
                return $list;
            }
        }

        return null;
    }

    public function existsForGroupWithName(int $pricingGroupId, string $name): bool
    {
        foreach ($this->byId as $list) {
            if ($list->pricingGroupId() === $pricingGroupId && strcasecmp($list->name(), $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function forGroup(int $pricingGroupId): array
    {
        $lists = array_values(array_filter($this->byId, static fn (PriceList $l): bool => $l->pricingGroupId() === $pricingGroupId));
        usort($lists, static fn (PriceList $a, PriceList $b): int => [$a->isControl() ? 0 : 1, $a->name()] <=> [$b->isControl() ? 0 : 1, $b->name()]);

        return $lists;
    }

    public function enabledForGroup(int $pricingGroupId): array
    {
        $lists = array_values(array_filter(
            $this->byId,
            static fn (PriceList $l): bool => $l->pricingGroupId() === $pricingGroupId && $l->isEnabled(),
        ));
        usort($lists, static fn (PriceList $a, PriceList $b): int => [$a->isControl() ? 0 : 1, $a->id() ?? 0] <=> [$b->isControl() ? 0 : 1, $b->id() ?? 0]);

        return $lists;
    }
}
