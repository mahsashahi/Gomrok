<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Vouchers\Domain\VoucherDecisionSnapshot;
use Gomrok\Modules\Vouchers\Domain\VoucherDecisionSnapshotRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoVoucherDecisionSnapshotRepository implements VoucherDecisionSnapshotRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function save(VoucherDecisionSnapshot $snapshot): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO voucher_decision_snapshots
                (checkout_attempt_id, client_id, voucher_id, voucher_redemption_id, voucher_code, voucher_name, created_at)
             VALUES (:attempt, :client, :voucher, :redemption, :code, :name, :now)',
        );
        $statement->execute([
            'attempt' => $snapshot->checkoutAttemptId,
            'client' => $snapshot->clientId,
            'voucher' => $snapshot->voucherId,
            'redemption' => $snapshot->voucherRedemptionId,
            'code' => $snapshot->voucherCode,
            'name' => $snapshot->voucherName,
            'now' => $snapshot->createdAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?VoucherDecisionSnapshot
    {
        $statement = $this->pdo->prepare('SELECT * FROM voucher_decision_snapshots WHERE checkout_attempt_id = :id');
        $statement->execute(['id' => $checkoutAttemptId]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): VoucherDecisionSnapshot
    {
        return new VoucherDecisionSnapshot(
            Row::int($row['id'] ?? null),
            Row::int($row['checkout_attempt_id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::int($row['voucher_id'] ?? null),
            Row::int($row['voucher_redemption_id'] ?? null),
            Row::str($row['voucher_code'] ?? ''),
            Row::str($row['voucher_name'] ?? ''),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
        );
    }
}
