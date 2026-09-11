<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application;

/**
 * Published read API for vouchers (CLI, Phase 27 admin, later the
 * `/vouchers/validate` endpoint in Phase 19).
 */
interface VoucherDirectory
{
    /**
     * @return list<VoucherSummary>
     */
    public function forClient(int $clientId): array;

    public function findByCode(int $clientId, string $code): ?VoucherSummary;

    public function findById(int $id): ?VoucherSummary;
}
