<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\PriceListAssignment;
use Gomrok\Modules\Pricing\Domain\PriceListAssignmentRepository;

final class InMemoryPriceListAssignmentRepository implements PriceListAssignmentRepository
{
    /** @var array<int, PriceListAssignment> */
    private array $byId = [];

    private int $nextId = 1;

    public function findByGroupAndHash(int $pricingGroupId, string $visitorRefHash): ?PriceListAssignment
    {
        foreach ($this->byId as $assignment) {
            if ($assignment->pricingGroupId() === $pricingGroupId && $assignment->visitorRefHash() === $visitorRefHash) {
                return $assignment;
            }
        }

        return null;
    }

    public function insertOrGetExisting(PriceListAssignment $assignment): PriceListAssignment
    {
        $existing = $this->findByGroupAndHash($assignment->pricingGroupId(), $assignment->visitorRefHash());
        if ($existing !== null) {
            return $existing;
        }

        $assignment->assignId($this->nextId++);
        $id = $assignment->id();
        \assert($id !== null);
        $this->byId[$id] = $assignment;

        return $assignment;
    }

    public function reassign(int $id, int $priceListId, DateTimeImmutable $reassignedAt): void
    {
        $assignment = $this->byId[$id] ?? null;
        if ($assignment !== null) {
            $assignment->reassignTo($priceListId, $reassignedAt);
        }
    }
}
