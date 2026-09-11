<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Vouchers\Application\VoucherUsagePort;
use Gomrok\Modules\Vouchers\Domain\RedemptionStatus;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemption;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemptionRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

/**
 * Backs both {@see VoucherRedemptionRepository} (Domain) and
 * {@see VoucherUsagePort} (Application) — both are just queries over
 * `voucher_redemptions`.
 */
final readonly class PdoVoucherRedemptionRepository implements VoucherRedemptionRepository, VoucherUsagePort
{
    private const DT = 'Y-m-d H:i:s';
    private const ACTIVE_STATUSES = ['reserved', 'confirmed'];

    public function __construct(private PDO $pdo)
    {
    }

    public function save(VoucherRedemption $redemption): void
    {
        if ($redemption->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO voucher_redemptions
                    (voucher_id, client_id, client_user_ref, attempt_reference, status, currency_code,
                     price_minor, nominal_discount_minor, applied_discount_minor, payable_minor,
                     reserved_at, confirmed_at, released_at, created_at, updated_at)
                 VALUES
                    (:voucher_id, :client_id, :client_user_ref, :attempt_reference, :status, :currency_code,
                     :price_minor, :nominal_discount_minor, :applied_discount_minor, :payable_minor,
                     :reserved_at, :confirmed_at, :released_at, :created_at, :updated_at)',
            );
            $statement->execute($this->params($redemption) + [
                'reserved_at' => $redemption->reservedAt()->format(self::DT),
                'created_at' => $redemption->createdAt()->format(self::DT),
                'updated_at' => $redemption->updatedAt()?->format(self::DT),
            ]);
            $redemption->assignId((int) $this->pdo->lastInsertId());

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE voucher_redemptions SET status = :status, confirmed_at = :confirmed_at, released_at = :released_at, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            'id' => $redemption->id(),
            'status' => $redemption->status()->value,
            'confirmed_at' => $redemption->confirmedAt()?->format(self::DT),
            'released_at' => $redemption->releasedAt()?->format(self::DT),
            'updated_at' => $redemption->updatedAt()?->format(self::DT) ?? gmdate(self::DT),
        ]);
    }

    public function findById(int $id): ?VoucherRedemption
    {
        $statement = $this->pdo->prepare('SELECT * FROM voucher_redemptions WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findByAttemptReference(int $voucherId, string $attemptReference): ?VoucherRedemption
    {
        $statement = $this->pdo->prepare('SELECT * FROM voucher_redemptions WHERE voucher_id = :v AND attempt_reference = :ref');
        $statement->execute(['v' => $voucherId, 'ref' => $attemptReference]);

        return $this->hydrate($statement->fetch());
    }

    public function countForVoucher(int $voucherId, array $statuses): int
    {
        return $this->count('SELECT COUNT(*) FROM voucher_redemptions WHERE voucher_id = :v', ['v' => $voucherId], $statuses);
    }

    public function countForVoucherAndUser(int $voucherId, string $clientUserRef, array $statuses): int
    {
        return $this->count(
            'SELECT COUNT(*) FROM voucher_redemptions WHERE voucher_id = :v AND client_user_ref = :u',
            ['v' => $voucherId, 'u' => $clientUserRef],
            $statuses,
        );
    }

    public function countForVoucherAndClient(int $voucherId, int $clientId, array $statuses): int
    {
        return $this->count(
            'SELECT COUNT(*) FROM voucher_redemptions WHERE voucher_id = :v AND client_id = :c',
            ['v' => $voucherId, 'c' => $clientId],
            $statuses,
        );
    }

    public function forVoucher(int $voucherId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM voucher_redemptions WHERE voucher_id = :v ORDER BY id ASC');
        $statement->execute(['v' => $voucherId]);

        $rows = [];
        while (($row = $statement->fetch()) !== false) {
            $redemption = $this->hydrate($row);
            if ($redemption !== null) {
                $rows[] = $redemption;
            }
        }

        return $rows;
    }

    public function redemptionsByUser(int $voucherId, string $clientUserRef): int
    {
        return $this->countForVoucherAndUser($voucherId, $clientUserRef, self::ACTIVE_STATUSES);
    }

    public function redemptionsByClient(int $voucherId, int $clientId): int
    {
        return $this->countForVoucherAndClient($voucherId, $clientId, self::ACTIVE_STATUSES);
    }

    public function activeReservations(int $voucherId): int
    {
        return $this->countForVoucher($voucherId, [RedemptionStatus::Reserved->value]);
    }

    /**
     * @param array<string, scalar> $params
     * @param list<string> $statuses
     */
    private function count(string $baseSql, array $params, array $statuses): int
    {
        if ($statuses === []) {
            return 0;
        }
        $placeholders = [];
        foreach ($statuses as $i => $status) {
            $key = "status{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $status;
        }
        $statement = $this->pdo->prepare($baseSql . ' AND status IN (' . implode(', ', $placeholders) . ')');
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, scalar|null>
     */
    private function params(VoucherRedemption $redemption): array
    {
        return [
            'voucher_id' => $redemption->voucherId(),
            'client_id' => $redemption->clientId(),
            'client_user_ref' => $redemption->clientUserRef(),
            'attempt_reference' => $redemption->attemptReference(),
            'status' => $redemption->status()->value,
            'currency_code' => $redemption->currencyCode(),
            'price_minor' => $redemption->priceMinor(),
            'nominal_discount_minor' => $redemption->nominalDiscountMinor(),
            'applied_discount_minor' => $redemption->appliedDiscountMinor(),
            'payable_minor' => $redemption->payableMinor(),
        ];
    }

    private function hydrate(mixed $row): ?VoucherRedemption
    {
        if (!\is_array($row)) {
            return null;
        }

        $confirmedAt = Row::nullableStr($row['confirmed_at'] ?? null);
        $releasedAt = Row::nullableStr($row['released_at'] ?? null);
        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);

        return VoucherRedemption::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['voucher_id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::nullableStr($row['client_user_ref'] ?? null),
            Row::str($row['attempt_reference'] ?? ''),
            RedemptionStatus::from(Row::str($row['status'] ?? 'reserved')),
            Row::str($row['currency_code'] ?? ''),
            Row::int($row['price_minor'] ?? null),
            Row::int($row['nominal_discount_minor'] ?? null),
            Row::int($row['applied_discount_minor'] ?? null),
            Row::int($row['payable_minor'] ?? null),
            new DateTimeImmutable(Row::str($row['reserved_at'] ?? 'now')),
            $confirmedAt !== null ? new DateTimeImmutable($confirmedAt) : null,
            $releasedAt !== null ? new DateTimeImmutable($releasedAt) : null,
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
