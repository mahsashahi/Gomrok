<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

/**
 * Persistence port for {@see Voucher}.
 */
interface VoucherRepository
{
    public function save(Voucher $voucher): void;

    public function findById(int $id): ?Voucher;

    /**
     * Locks the row (`SELECT ... FOR UPDATE`) for the duration of the caller's
     * transaction (Phase 17 Q3) — every redemption write goes through this
     * first, making the `vouchers` row the de facto per-voucher mutex.
     */
    public function findByIdForUpdate(int $id): ?Voucher;

    public function findByCode(int $clientId, string $code): ?Voucher;

    public function existsForClientWithCode(int $clientId, string $code): bool;

    /**
     * Atomically bumps the global confirmed tally. Only
     * {@see \Gomrok\Modules\Vouchers\Application\ConfirmVoucherRedemption\ConfirmVoucherRedemptionHandler}
     * calls this, and only once per redemption (Phase 17).
     */
    public function incrementRedeemedCount(int $id): void;

    /**
     * @return list<Voucher>
     */
    public function forClient(int $clientId): array;
}
