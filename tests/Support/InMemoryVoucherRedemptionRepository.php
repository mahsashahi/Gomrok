<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Vouchers\Application\VoucherUsagePort;
use Gomrok\Modules\Vouchers\Domain\RedemptionStatus;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemption;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemptionRepository;

/**
 * Backs both {@see VoucherRedemptionRepository} and {@see VoucherUsagePort} —
 * mirrors the production `PdoVoucherRedemptionRepository` shape.
 */
final class InMemoryVoucherRedemptionRepository implements VoucherRedemptionRepository, VoucherUsagePort
{
    private const ACTIVE_STATUSES = ['reserved', 'confirmed'];

    /** @var array<int, VoucherRedemption> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(VoucherRedemption $redemption): void
    {
        if ($redemption->id() === null) {
            $redemption->assignId($this->nextId++);
        }
        $id = $redemption->id();
        \assert($id !== null);
        $this->byId[$id] = $redemption;
    }

    public function findById(int $id): ?VoucherRedemption
    {
        return $this->byId[$id] ?? null;
    }

    public function findByAttemptReference(int $voucherId, string $attemptReference): ?VoucherRedemption
    {
        foreach ($this->byId as $redemption) {
            if ($redemption->voucherId() === $voucherId && $redemption->attemptReference() === $attemptReference) {
                return $redemption;
            }
        }

        return null;
    }

    public function countForVoucher(int $voucherId, array $statuses): int
    {
        return \count(array_filter(
            $this->byId,
            static fn (VoucherRedemption $r): bool => $r->voucherId() === $voucherId && \in_array($r->status()->value, $statuses, true),
        ));
    }

    public function countForVoucherAndUser(int $voucherId, string $clientUserRef, array $statuses): int
    {
        return \count(array_filter(
            $this->byId,
            static fn (VoucherRedemption $r): bool => $r->voucherId() === $voucherId
                && $r->clientUserRef() === $clientUserRef
                && \in_array($r->status()->value, $statuses, true),
        ));
    }

    public function countForVoucherAndClient(int $voucherId, int $clientId, array $statuses): int
    {
        return \count(array_filter(
            $this->byId,
            static fn (VoucherRedemption $r): bool => $r->voucherId() === $voucherId
                && $r->clientId() === $clientId
                && \in_array($r->status()->value, $statuses, true),
        ));
    }

    public function forVoucher(int $voucherId): array
    {
        return array_values(array_filter($this->byId, static fn (VoucherRedemption $r): bool => $r->voucherId() === $voucherId));
    }

    public function redemptionsByUser(int $voucherId, string $clientUserRef): int
    {
        return $this->countForVoucherAndUser($voucherId, $clientUserRef, self::ACTIVE_STATUSES);
    }

    public function redemptionsByClient(int $voucherId, int $clientId): int
    {
        return $this->countForVoucherAndClient($voucherId, $clientId, self::ACTIVE_STATUSES);
    }

    public function activeReservations(int $voucherId): int
    {
        return $this->countForVoucher($voucherId, [RedemptionStatus::Reserved->value]);
    }
}
