<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackage;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackageRepository;
use Gomrok\Modules\Pricing\Domain\PricingRowStatus;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoPricingGroupPackageRepository implements PricingGroupPackageRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(PricingGroupPackage $row): void
    {
        if ($row->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO pricing_group_packages
                    (pricing_group_id, package_id, status, amount_minor, currency_code,
                     name_override, badge_override, highlighted_override, display_order, created_at, updated_at)
                 VALUES
                    (:group_id, :package_id, :status, :amount_minor, :currency_code,
                     :name_override, :badge_override, :highlighted_override, :display_order, :created_at, :updated_at)',
            );
            $statement->execute($this->params($row) + [
                'created_at' => $row->createdAt()->format(self::DT),
                'updated_at' => $row->updatedAt()?->format(self::DT),
            ]);
            $row->assignId((int) $this->pdo->lastInsertId());

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE pricing_group_packages SET
                status = :status, amount_minor = :amount_minor, currency_code = :currency_code,
                name_override = :name_override, badge_override = :badge_override,
                highlighted_override = :highlighted_override, display_order = :display_order, updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute($this->params($row) + [
            'id' => $row->id(),
            'updated_at' => gmdate(self::DT),
        ]);
    }

    public function find(int $pricingGroupId, int $packageId): ?PricingGroupPackage
    {
        $statement = $this->pdo->prepare('SELECT * FROM pricing_group_packages WHERE pricing_group_id = :g AND package_id = :p');
        $statement->execute(['g' => $pricingGroupId, 'p' => $packageId]);

        return $this->hydrate($statement->fetch());
    }

    public function forGroup(int $pricingGroupId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pricing_group_packages WHERE pricing_group_id = :id ORDER BY display_order ASC, package_id ASC');
        $statement->execute(['id' => $pricingGroupId]);

        $rows = [];
        while (($row = $statement->fetch()) !== false) {
            $hydrated = $this->hydrate($row);
            if ($hydrated !== null) {
                $rows[] = $hydrated;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function params(PricingGroupPackage $row): array
    {
        return [
            'group_id' => $row->pricingGroupId(),
            'package_id' => $row->packageId(),
            'status' => $row->status()->value,
            'amount_minor' => $row->amountMinor(),
            'currency_code' => $row->currencyCode(),
            'name_override' => $row->nameOverride(),
            'badge_override' => $row->badgeOverride(),
            'highlighted_override' => $row->highlightedOverride() === null ? null : ($row->highlightedOverride() ? 1 : 0),
            'display_order' => $row->displayOrder(),
        ];
    }

    private function hydrate(mixed $row): ?PricingGroupPackage
    {
        if (!\is_array($row)) {
            return null;
        }

        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);
        $highlighted = $row['highlighted_override'] ?? null;

        return PricingGroupPackage::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['pricing_group_id'] ?? null),
            Row::int($row['package_id'] ?? null),
            PricingRowStatus::from(Row::str($row['status'] ?? 'default')),
            Row::nullableInt($row['amount_minor'] ?? null),
            Row::nullableStr($row['currency_code'] ?? null),
            Row::nullableStr($row['name_override'] ?? null),
            Row::nullableStr($row['badge_override'] ?? null),
            $highlighted === null ? null : Row::bool($highlighted),
            Row::int($row['display_order'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
