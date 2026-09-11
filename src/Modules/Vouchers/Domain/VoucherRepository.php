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

    public function findByCode(int $clientId, string $code): ?Voucher;

    public function existsForClientWithCode(int $clientId, string $code): bool;

    /**
     * @return list<Voucher>
     */
    public function forClient(int $clientId): array;
}
