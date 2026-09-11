<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherStatus;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoVoucherRepository implements VoucherRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(Voucher $voucher): void
    {
        if ($voucher->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO vouchers
                    (client_id, code, name, description, status, valid_from, valid_until, first_purchase_only,
                     min_purchase_minor, min_purchase_currency, default_discount_type, default_percent_bp,
                     max_total_redemptions, max_per_user, max_per_client, redeemed_count, created_at, updated_at)
                 VALUES
                    (:client_id, :code, :name, :description, :status, :valid_from, :valid_until, :first_purchase_only,
                     :min_purchase_minor, :min_purchase_currency, :default_discount_type, :default_percent_bp,
                     :max_total_redemptions, :max_per_user, :max_per_client, 0, :created_at, :updated_at)',
            );
            $statement->execute($this->params($voucher) + [
                'created_at' => $voucher->createdAt()->format(self::DT),
                'updated_at' => $voucher->updatedAt()?->format(self::DT),
            ]);
            $voucher->assignId((int) $this->pdo->lastInsertId());

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE vouchers SET
                name = :name, description = :description, status = :status,
                valid_from = :valid_from, valid_until = :valid_until, first_purchase_only = :first_purchase_only,
                min_purchase_minor = :min_purchase_minor, min_purchase_currency = :min_purchase_currency,
                default_discount_type = :default_discount_type, default_percent_bp = :default_percent_bp,
                max_total_redemptions = :max_total_redemptions, max_per_user = :max_per_user, max_per_client = :max_per_client,
                updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute($this->params($voucher) + [
            'id' => $voucher->id(),
            'updated_at' => $voucher->updatedAt()?->format(self::DT) ?? gmdate(self::DT),
        ]);
    }

    public function findById(int $id): ?Voucher
    {
        $statement = $this->pdo->prepare('SELECT * FROM vouchers WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findByCode(int $clientId, string $code): ?Voucher
    {
        $statement = $this->pdo->prepare('SELECT * FROM vouchers WHERE client_id = :c AND code = :code');
        $statement->execute(['c' => $clientId, 'code' => strtoupper(trim($code))]);

        return $this->hydrate($statement->fetch());
    }

    public function existsForClientWithCode(int $clientId, string $code): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM vouchers WHERE client_id = :c AND code = :code');
        $statement->execute(['c' => $clientId, 'code' => strtoupper(trim($code))]);

        return $statement->fetchColumn() !== false;
    }

    public function forClient(int $clientId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM vouchers WHERE client_id = :c ORDER BY code ASC');
        $statement->execute(['c' => $clientId]);

        $vouchers = [];
        while (($row = $statement->fetch()) !== false) {
            $voucher = $this->hydrate($row);
            if ($voucher !== null) {
                $vouchers[] = $voucher;
            }
        }

        return $vouchers;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function params(Voucher $voucher): array
    {
        return [
            'client_id' => $voucher->clientId(),
            'code' => $voucher->code(),
            'name' => $voucher->name(),
            'description' => $voucher->description(),
            'status' => $voucher->status()->value,
            'valid_from' => $voucher->validFrom()?->format(self::DT),
            'valid_until' => $voucher->validUntil()?->format(self::DT),
            'first_purchase_only' => $voucher->firstPurchaseOnly() ? 1 : 0,
            'min_purchase_minor' => $voucher->minPurchaseMinor(),
            'min_purchase_currency' => $voucher->minPurchaseCurrency(),
            'default_discount_type' => $voucher->defaultDiscountType()->value,
            'default_percent_bp' => $voucher->defaultPercentBp(),
            'max_total_redemptions' => $voucher->maxTotalRedemptions(),
            'max_per_user' => $voucher->maxPerUser(),
            'max_per_client' => $voucher->maxPerClient(),
        ];
    }

    private function hydrate(mixed $row): ?Voucher
    {
        if (!\is_array($row)) {
            return null;
        }

        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);
        $validFrom = Row::nullableStr($row['valid_from'] ?? null);
        $validUntil = Row::nullableStr($row['valid_until'] ?? null);

        return Voucher::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::str($row['code'] ?? ''),
            Row::str($row['name'] ?? ''),
            Row::nullableStr($row['description'] ?? null),
            VoucherStatus::from(Row::str($row['status'] ?? 'active')),
            $validFrom !== null ? new DateTimeImmutable($validFrom) : null,
            $validUntil !== null ? new DateTimeImmutable($validUntil) : null,
            Row::bool($row['first_purchase_only'] ?? null),
            Row::nullableInt($row['min_purchase_minor'] ?? null),
            Row::nullableStr($row['min_purchase_currency'] ?? null),
            DefaultDiscountType::from(Row::str($row['default_discount_type'] ?? 'none')),
            Row::nullableInt($row['default_percent_bp'] ?? null),
            Row::nullableInt($row['max_total_redemptions'] ?? null),
            Row::nullableInt($row['max_per_user'] ?? null),
            Row::nullableInt($row['max_per_client'] ?? null),
            Row::int($row['redeemed_count'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
