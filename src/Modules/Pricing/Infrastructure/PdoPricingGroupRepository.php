<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Modules\Pricing\Domain\PricingGroupStatus;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

/**
 * MySQL {@see PricingGroupRepository}. `save()` writes the `pricing_groups` row
 * then rebuilds `pricing_group_countries` delete-and-reinsert.
 */
final readonly class PdoPricingGroupRepository implements PricingGroupRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(PricingGroup $group): void
    {
        if ($group->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO pricing_groups
                    (client_id, slug, name, priority, device_type, currency_code, is_default, status, created_at, updated_at)
                 VALUES
                    (:client_id, :slug, :name, :priority, :device_type, :currency_code, :is_default, :status, :created_at, :updated_at)',
            );
            $statement->execute([
                'client_id' => $group->clientId(),
                'slug' => $group->slug()->value,
                'name' => $group->name(),
                'priority' => $group->priority(),
                'device_type' => $group->deviceType(),
                'currency_code' => $group->currencyCode(),
                'is_default' => $group->isDefault() ? 1 : 0,
                'status' => $group->status()->value,
                'created_at' => $group->createdAt()->format(self::DT),
                'updated_at' => $group->updatedAt()?->format(self::DT),
            ]);
            $group->assignId((int) $this->pdo->lastInsertId());
        } else {
            $statement = $this->pdo->prepare(
                'UPDATE pricing_groups SET name = :name, priority = :priority, status = :status, updated_at = :updated_at WHERE id = :id',
            );
            $statement->execute([
                'id' => $group->id(),
                'name' => $group->name(),
                'priority' => $group->priority(),
                'status' => $group->status()->value,
                'updated_at' => $group->updatedAt()?->format(self::DT),
            ]);
        }

        $id = $group->id();
        \assert($id !== null);

        $this->pdo->prepare('DELETE FROM pricing_group_countries WHERE pricing_group_id = :id')->execute(['id' => $id]);
        if ($group->countryCodes() !== []) {
            $insert = $this->pdo->prepare(
                'INSERT INTO pricing_group_countries (pricing_group_id, country_code, created_at) VALUES (:id, :code, :now)',
            );
            $now = gmdate(self::DT);
            foreach ($group->countryCodes() as $code) {
                $insert->execute(['id' => $id, 'code' => $code, 'now' => $now]);
            }
        }
    }

    public function findById(int $id): ?PricingGroup
    {
        $statement = $this->pdo->prepare('SELECT * FROM pricing_groups WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findByClientAndSlug(int $clientId, string $slug): ?PricingGroup
    {
        $statement = $this->pdo->prepare('SELECT * FROM pricing_groups WHERE client_id = :client_id AND slug = :slug');
        $statement->execute(['client_id' => $clientId, 'slug' => $slug]);

        return $this->hydrate($statement->fetch());
    }

    public function existsForClientWithSlug(int $clientId, string $slug): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM pricing_groups WHERE client_id = :client_id AND slug = :slug LIMIT 1');
        $statement->execute(['client_id' => $clientId, 'slug' => $slug]);

        return $statement->fetchColumn() !== false;
    }

    public function forClient(int $clientId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pricing_groups WHERE client_id = :client_id ORDER BY is_default ASC, priority ASC, slug ASC');
        $statement->execute(['client_id' => $clientId]);

        $groups = [];
        while (($row = $statement->fetch()) !== false) {
            $group = $this->hydrate($row);
            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    private function hydrate(mixed $row): ?PricingGroup
    {
        if (!\is_array($row)) {
            return null;
        }

        $id = Row::int($row['id'] ?? null);
        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);

        return PricingGroup::fromStorage(
            $id,
            Row::int($row['client_id'] ?? null),
            PricingGroupSlug::of(Row::str($row['slug'] ?? '')),
            Row::str($row['name'] ?? ''),
            Row::int($row['priority'] ?? null),
            Row::nullableStr($row['device_type'] ?? null),
            Row::str($row['currency_code'] ?? ''),
            Row::bool($row['is_default'] ?? null),
            PricingGroupStatus::from(Row::str($row['status'] ?? 'active')),
            $this->countriesOf($id),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }

    /**
     * @return list<string>
     */
    private function countriesOf(int $groupId): array
    {
        $statement = $this->pdo->prepare('SELECT country_code FROM pricing_group_countries WHERE pricing_group_id = :id ORDER BY country_code');
        $statement->execute(['id' => $groupId]);

        $codes = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $codes[] = Row::str($value);
        }

        return $codes;
    }
}
