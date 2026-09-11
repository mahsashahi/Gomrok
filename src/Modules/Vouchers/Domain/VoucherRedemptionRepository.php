<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

/**
 * Persistence port for {@see VoucherRedemption}.
 */
interface VoucherRedemptionRepository
{
    public function save(VoucherRedemption $redemption): void;

    public function findById(int $id): ?VoucherRedemption;

    public function findByAttemptReference(int $voucherId, string $attemptReference): ?VoucherRedemption;

    /**
     * @param list<string> $statuses RedemptionStatus values
     */
    public function countForVoucher(int $voucherId, array $statuses): int;

    /**
     * @param list<string> $statuses RedemptionStatus values
     */
    public function countForVoucherAndUser(int $voucherId, string $clientUserRef, array $statuses): int;

    /**
     * @param list<string> $statuses RedemptionStatus values
     */
    public function countForVoucherAndClient(int $voucherId, int $clientId, array $statuses): int;

    /**
     * @return list<VoucherRedemption>
     */
    public function forVoucher(int $voucherId): array;
}
