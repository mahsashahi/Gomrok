<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Infrastructure;

use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityDimension;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityRule;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityRuleRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoVoucherEligibilityRuleRepository implements VoucherEligibilityRuleRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function replaceForVoucher(int $voucherId, array $rules): void
    {
        $this->pdo->prepare('DELETE FROM voucher_eligibility_rules WHERE voucher_id = :id')->execute(['id' => $voucherId]);

        if ($rules === []) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO voucher_eligibility_rules (voucher_id, dimension, value, created_at) VALUES (:voucher_id, :dimension, :value, :now)',
        );
        foreach ($rules as $rule) {
            $statement->execute([
                'voucher_id' => $rule->voucherId,
                'dimension' => $rule->dimension->value,
                'value' => $rule->value,
                'now' => $now,
            ]);
        }
    }

    public function forVoucher(int $voucherId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM voucher_eligibility_rules WHERE voucher_id = :id ORDER BY dimension ASC, value ASC');
        $statement->execute(['id' => $voucherId]);

        $rules = [];
        while (($row = $statement->fetch()) !== false) {
            if (!\is_array($row)) {
                continue;
            }
            $dimension = VoucherEligibilityDimension::tryFrom(Row::str($row['dimension'] ?? ''));
            if ($dimension === null) {
                continue;
            }
            $rules[] = new VoucherEligibilityRule(Row::int($row['voucher_id'] ?? null), $dimension, Row::str($row['value'] ?? ''));
        }

        return $rules;
    }
}
