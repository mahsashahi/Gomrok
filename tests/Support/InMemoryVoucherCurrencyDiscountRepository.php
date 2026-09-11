<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscount;
use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscountRepository;

final class InMemoryVoucherCurrencyDiscountRepository implements VoucherCurrencyDiscountRepository
{
    /** @var array<string, VoucherCurrencyDiscount> */
    private array $rows = [];

    public function save(VoucherCurrencyDiscount $discount): void
    {
        $this->rows[$discount->voucherId . ':' . $discount->currencyCode] = $discount;
    }

    public function find(int $voucherId, string $currencyCode): ?VoucherCurrencyDiscount
    {
        return $this->rows[$voucherId . ':' . strtoupper($currencyCode)] ?? null;
    }

    public function delete(int $voucherId, string $currencyCode): bool
    {
        $key = $voucherId . ':' . strtoupper($currencyCode);
        if (!isset($this->rows[$key])) {
            return false;
        }
        unset($this->rows[$key]);

        return true;
    }

    public function forVoucher(int $voucherId): array
    {
        return array_values(array_filter($this->rows, static fn (VoucherCurrencyDiscount $d): bool => $d->voucherId === $voucherId));
    }
}
