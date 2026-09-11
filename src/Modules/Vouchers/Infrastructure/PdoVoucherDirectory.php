<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Infrastructure;

use Gomrok\Modules\Vouchers\Application\VoucherDirectory;
use Gomrok\Modules\Vouchers\Application\VoucherSummary;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoVoucherDirectory implements VoucherDirectory
{
    public function __construct(private PDO $pdo)
    {
    }

    public function forClient(int $clientId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM vouchers WHERE client_id = :c ORDER BY code ASC');
        $statement->execute(['c' => $clientId]);

        $out = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $out[] = $this->toSummary($row);
            }
        }

        return $out;
    }

    public function findByCode(int $clientId, string $code): ?VoucherSummary
    {
        $statement = $this->pdo->prepare('SELECT * FROM vouchers WHERE client_id = :c AND code = :code');
        $statement->execute(['c' => $clientId, 'code' => strtoupper(trim($code))]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->toSummary($row) : null;
    }

    public function findById(int $id): ?VoucherSummary
    {
        $statement = $this->pdo->prepare('SELECT * FROM vouchers WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->toSummary($row) : null;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function toSummary(array $row): VoucherSummary
    {
        $id = Row::int($row['id'] ?? null);

        $rules = $this->pdo->prepare('SELECT dimension, value FROM voucher_eligibility_rules WHERE voucher_id = :id ORDER BY dimension ASC, value ASC');
        $rules->execute(['id' => $id]);
        $eligibilityRules = [];
        while (($r = $rules->fetch()) !== false) {
            if (\is_array($r)) {
                $eligibilityRules[] = ['dimension' => Row::str($r['dimension'] ?? ''), 'value' => Row::str($r['value'] ?? '')];
            }
        }

        $discounts = $this->pdo->prepare('SELECT currency_code, discount_type, percent_bp, amount_minor, max_discount_minor FROM voucher_currency_discounts WHERE voucher_id = :id ORDER BY currency_code ASC');
        $discounts->execute(['id' => $id]);
        $currencyDiscounts = [];
        while (($d = $discounts->fetch()) !== false) {
            if (\is_array($d)) {
                $currencyDiscounts[] = [
                    'currency' => Row::str($d['currency_code'] ?? ''),
                    'discount_type' => Row::str($d['discount_type'] ?? ''),
                    'percent_bp' => Row::nullableInt($d['percent_bp'] ?? null),
                    'amount_minor' => Row::nullableInt($d['amount_minor'] ?? null),
                    'max_discount_minor' => Row::nullableInt($d['max_discount_minor'] ?? null),
                ];
            }
        }

        return new VoucherSummary(
            $id,
            Row::int($row['client_id'] ?? null),
            Row::str($row['code'] ?? ''),
            Row::str($row['name'] ?? ''),
            Row::nullableStr($row['description'] ?? null),
            Row::str($row['status'] ?? 'active'),
            Row::nullableStr($row['valid_from'] ?? null),
            Row::nullableStr($row['valid_until'] ?? null),
            Row::bool($row['first_purchase_only'] ?? null),
            Row::nullableInt($row['min_purchase_minor'] ?? null),
            Row::nullableStr($row['min_purchase_currency'] ?? null),
            Row::str($row['default_discount_type'] ?? 'none'),
            Row::nullableInt($row['default_percent_bp'] ?? null),
            Row::nullableInt($row['max_total_redemptions'] ?? null),
            Row::nullableInt($row['max_per_user'] ?? null),
            Row::nullableInt($row['max_per_client'] ?? null),
            Row::int($row['redeemed_count'] ?? null),
            $eligibilityRules,
            $currencyDiscounts,
        );
    }
}
