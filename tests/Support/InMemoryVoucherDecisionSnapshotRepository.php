<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Vouchers\Domain\VoucherDecisionSnapshot;
use Gomrok\Modules\Vouchers\Domain\VoucherDecisionSnapshotRepository;

final class InMemoryVoucherDecisionSnapshotRepository implements VoucherDecisionSnapshotRepository
{
    /** @var array<int, VoucherDecisionSnapshot> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(VoucherDecisionSnapshot $snapshot): int
    {
        $id = $this->nextId++;
        $this->byId[$id] = new VoucherDecisionSnapshot(
            $id,
            $snapshot->checkoutAttemptId,
            $snapshot->clientId,
            $snapshot->voucherId,
            $snapshot->voucherRedemptionId,
            $snapshot->voucherCode,
            $snapshot->voucherName,
            $snapshot->createdAt,
        );

        return $id;
    }

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?VoucherDecisionSnapshot
    {
        foreach ($this->byId as $snapshot) {
            if ($snapshot->checkoutAttemptId === $checkoutAttemptId) {
                return $snapshot;
            }
        }

        return null;
    }
}
