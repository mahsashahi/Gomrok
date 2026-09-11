<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshot;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshotRepository;

final class InMemoryPricingDecisionSnapshotRepository implements PricingDecisionSnapshotRepository
{
    /** @var array<int, PricingDecisionSnapshot> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(PricingDecisionSnapshot $snapshot): int
    {
        $id = $this->nextId++;
        $this->byId[$id] = new PricingDecisionSnapshot(
            $id,
            $snapshot->checkoutAttemptId,
            $snapshot->clientId,
            $snapshot->packageId,
            $snapshot->currencyCode,
            $snapshot->amountMinor,
            $snapshot->source,
            $snapshot->payload,
            $snapshot->createdAt,
        );

        return $id;
    }

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?PricingDecisionSnapshot
    {
        foreach ($this->byId as $snapshot) {
            if ($snapshot->checkoutAttemptId === $checkoutAttemptId) {
                return $snapshot;
            }
        }

        return null;
    }
}
