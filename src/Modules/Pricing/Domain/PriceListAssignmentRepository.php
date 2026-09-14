<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

use DateTimeImmutable;

/**
 * Persistence port for {@see PriceListAssignment}.
 */
interface PriceListAssignmentRepository
{
    public function findByGroupAndHash(int $pricingGroupId, string $visitorRefHash): ?PriceListAssignment;

    /**
     * Inserts a brand-new assignment (`$assignment->id()` is null). If a
     * concurrent request already created one for the same
     * `(pricingGroupId, visitorRefHash)` pair, returns that existing row
     * instead of throwing — the caller never needs to handle the race.
     */
    public function insertOrGetExisting(PriceListAssignment $assignment): PriceListAssignment;

    public function reassign(int $id, int $priceListId, DateTimeImmutable $reassignedAt): void;
}
