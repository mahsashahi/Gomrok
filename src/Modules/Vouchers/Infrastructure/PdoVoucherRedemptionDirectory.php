<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Infrastructure;

use Gomrok\Modules\Vouchers\Application\VoucherRedemptionDirectory;
use Gomrok\Modules\Vouchers\Application\VoucherRedemptionSummary;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoVoucherRedemptionDirectory implements VoucherRedemptionDirectory
{
    public function __construct(private PDO $pdo)
    {
    }

    public function forVoucher(int $voucherId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM voucher_redemptions WHERE voucher_id = :v ORDER BY id ASC');
        $statement->execute(['v' => $voucherId]);

        $out = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $out[] = new VoucherRedemptionSummary(
                    Row::int($row['id'] ?? null),
                    Row::int($row['voucher_id'] ?? null),
                    Row::nullableStr($row['client_user_ref'] ?? null),
                    Row::str($row['attempt_reference'] ?? ''),
                    Row::str($row['status'] ?? 'reserved'),
                    Row::str($row['currency_code'] ?? ''),
                    Row::int($row['price_minor'] ?? null),
                    Row::int($row['nominal_discount_minor'] ?? null),
                    Row::int($row['applied_discount_minor'] ?? null),
                    Row::int($row['payable_minor'] ?? null),
                    Row::str($row['reserved_at'] ?? ''),
                    Row::nullableStr($row['confirmed_at'] ?? null),
                    Row::nullableStr($row['released_at'] ?? null),
                );
            }
        }

        return $out;
    }
}
