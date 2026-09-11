<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

/**
 * Persistence port for {@see VoucherCurrencyDiscount}.
 */
interface VoucherCurrencyDiscountRepository
{
    /** Insert or update (unique on `(voucher_id, currency_code)`). */
    public function save(VoucherCurrencyDiscount $discount): void;

    public function find(int $voucherId, string $currencyCode): ?VoucherCurrencyDiscount;

    public function delete(int $voucherId, string $currencyCode): bool;

    /**
     * @return list<VoucherCurrencyDiscount>
     */
    public function forVoucher(int $voucherId): array;
}
