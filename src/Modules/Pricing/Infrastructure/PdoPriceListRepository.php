<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\PriceList;
use Gomrok\Modules\Pricing\Domain\PriceListRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoPriceListRepository implements PriceListRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(PriceList $list): void
    {
        if ($list->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO price_lists (client_id, pricing_group_id, name, is_control, factor, is_enabled, created_at, updated_at)
                 VALUES (:client_id, :pricing_group_id, :name, :is_control, :factor, :is_enabled, :created_at, :updated_at)',
            );
            $statement->execute([
                'client_id' => $list->clientId(),
                'pricing_group_id' => $list->pricingGroupId(),
                'name' => $list->name(),
                'is_control' => $list->isControl() ? 1 : 0,
                'factor' => $list->factor(),
                'is_enabled' => $list->isEnabled() ? 1 : 0,
                'created_at' => $list->createdAt()->format(self::DT),
                'updated_at' => $list->updatedAt()?->format(self::DT),
            ]);
            $list->assignId((int) $this->pdo->lastInsertId());

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE price_lists SET name = :name, factor = :factor, is_enabled = :is_enabled, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            'id' => $list->id(),
            'name' => $list->name(),
            'factor' => $list->factor(),
            'is_enabled' => $list->isEnabled() ? 1 : 0,
            'updated_at' => $list->updatedAt()?->format(self::DT) ?? gmdate(self::DT),
        ]);
    }

    public function findById(int $id): ?PriceList
    {
        $statement = $this->pdo->prepare('SELECT * FROM price_lists WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function delete(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM price_lists WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    public function findControlForGroup(int $pricingGroupId): ?PriceList
    {
        $statement = $this->pdo->prepare('SELECT * FROM price_lists WHERE pricing_group_id = :g AND is_control = 1');
        $statement->execute(['g' => $pricingGroupId]);

        return $this->hydrate($statement->fetch());
    }

    public function existsForGroupWithName(int $pricingGroupId, string $name): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM price_lists WHERE pricing_group_id = :g AND name = :n');
        $statement->execute(['g' => $pricingGroupId, 'n' => $name]);

        return $statement->fetchColumn() !== false;
    }

    public function forGroup(int $pricingGroupId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM price_lists WHERE pricing_group_id = :g ORDER BY is_control DESC, name ASC');
        $statement->execute(['g' => $pricingGroupId]);

        return $this->hydrateAll($statement);
    }

    public function enabledForGroup(int $pricingGroupId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM price_lists WHERE pricing_group_id = :g AND is_enabled = 1 ORDER BY is_control DESC, id ASC');
        $statement->execute(['g' => $pricingGroupId]);

        return $this->hydrateAll($statement);
    }

    /**
     * @return list<PriceList>
     */
    private function hydrateAll(\PDOStatement $statement): array
    {
        $lists = [];
        while (($row = $statement->fetch()) !== false) {
            $list = $this->hydrate($row);
            if ($list !== null) {
                $lists[] = $list;
            }
        }

        return $lists;
    }

    private function hydrate(mixed $row): ?PriceList
    {
        if (!\is_array($row)) {
            return null;
        }

        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);

        return PriceList::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::int($row['pricing_group_id'] ?? null),
            Row::str($row['name'] ?? ''),
            Row::bool($row['is_control'] ?? null),
            Row::str($row['factor'] ?? '1.0000'),
            Row::bool($row['is_enabled'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
