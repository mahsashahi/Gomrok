<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshotRepository;

final class InMemoryProviderRoutingDecisionSnapshotRepository implements ProviderRoutingDecisionSnapshotRepository
{
    /** @var array<int, ProviderRoutingDecisionSnapshot> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(ProviderRoutingDecisionSnapshot $snapshot): int
    {
        $id = $this->nextId++;
        $this->byId[$id] = new ProviderRoutingDecisionSnapshot(
            $id,
            $snapshot->checkoutAttemptId,
            $snapshot->clientId,
            $snapshot->providerAccountId,
            $snapshot->paymentMethod,
            $snapshot->purchaseType,
            $snapshot->payload,
            $snapshot->createdAt,
        );

        return $id;
    }

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?ProviderRoutingDecisionSnapshot
    {
        foreach ($this->byId as $snapshot) {
            if ($snapshot->checkoutAttemptId === $checkoutAttemptId) {
                return $snapshot;
            }
        }

        return null;
    }
}
