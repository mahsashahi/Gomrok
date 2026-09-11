<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Infrastructure;

use Gomrok\Modules\Vouchers\Domain\DiscountType;
use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscount;
use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscountRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoVoucherCurrencyDiscountRepository implements VoucherCurrencyDiscountRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function save(VoucherCurrencyDiscount $discount): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO voucher_currency_discounts (voucher_id, currency_code, discount_type, percent_bp, amount_minor, max_discount_minor, created_at, updated_at)
             VALUES (:voucher_id, :currency_code, :discount_type, :percent_bp, :amount_minor, :max_discount_minor, :now, :now)
             ON DUPLICATE KEY UPDATE discount_type = VALUES(discount_type), percent_bp = VALUES(percent_bp),
                amount_minor = VALUES(amount_minor), max_discount_minor = VALUES(max_discount_minor), updated_at = VALUES(updated_at)',
        );
        $statement->execute([
            'voucher_id' => $discount->voucherId,
            'currency_code' => $discount->currencyCode,
            'discount_type' => $discount->discountType->value,
            'percent_bp' => $discount->percentBp,
            'amount_minor' => $discount->amountMinor,
            'max_discount_minor' => $discount->maxDiscountMinor,
            'now' => $now,
        ]);
    }

    public function find(int $voucherId, string $currencyCode): ?VoucherCurrencyDiscount
    {
        $statement = $this->pdo->prepare('SELECT * FROM voucher_currency_discounts WHERE voucher_id = :v AND currency_code = :c');
        $statement->execute(['v' => $voucherId, 'c' => strtoupper($currencyCode)]);

        return $this->hydrate($statement->fetch());
    }

    public function delete(int $voucherId, string $currencyCode): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM voucher_currency_discounts WHERE voucher_id = :v AND currency_code = :c');
        $statement->execute(['v' => $voucherId, 'c' => strtoupper($currencyCode)]);

        return $statement->rowCount() > 0;
    }

    public function forVoucher(int $voucherId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM voucher_currency_discounts WHERE voucher_id = :v ORDER BY currency_code ASC');
        $statement->execute(['v' => $voucherId]);

        $discounts = [];
        while (($row = $statement->fetch()) !== false) {
            $discount = $this->hydrate($row);
            if ($discount !== null) {
                $discounts[] = $discount;
            }
        }

        return $discounts;
    }

    private function hydrate(mixed $row): ?VoucherCurrencyDiscount
    {
        if (!\is_array($row)) {
            return null;
        }

        return new VoucherCurrencyDiscount(
            Row::int($row['voucher_id'] ?? null),
            Row::str($row['currency_code'] ?? ''),
            DiscountType::from(Row::str($row['discount_type'] ?? 'fixed')),
            Row::nullableInt($row['percent_bp'] ?? null),
            Row::nullableInt($row['amount_minor'] ?? null),
            Row::nullableInt($row['max_discount_minor'] ?? null),
        );
    }
}
