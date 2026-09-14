<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\PriceListAssignment;
use Gomrok\Modules\Pricing\Domain\PriceListAssignmentRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoPriceListAssignmentRepository implements PriceListAssignmentRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function findByGroupAndHash(int $pricingGroupId, string $visitorRefHash): ?PriceListAssignment
    {
        $statement = $this->pdo->prepare('SELECT * FROM price_list_assignments WHERE pricing_group_id = :g AND visitor_ref_hash = :h');
        $statement->execute(['g' => $pricingGroupId, 'h' => $visitorRefHash]);

        return $this->hydrate($statement->fetch());
    }

    public function insertOrGetExisting(PriceListAssignment $assignment): PriceListAssignment
    {
        \assert($assignment->id() === null);

        // `id = LAST_INSERT_ID(id)` is the standard MySQL upsert-race idiom:
        // on a fresh insert it's a no-op that still lets lastInsertId() return
        // the new row's id; on a conflict (a concurrent request already
        // created this (pricing_group_id, visitor_ref_hash) pair) it makes
        // lastInsertId() return the EXISTING row's id instead of throwing —
        // so the caller always gets the one authoritative, persisted row.
        $statement = $this->pdo->prepare(
            'INSERT INTO price_list_assignments (client_id, pricing_group_id, visitor_ref_hash, price_list_id, assigned_at, created_at)
             VALUES (:client_id, :pricing_group_id, :visitor_ref_hash, :price_list_id, :assigned_at, :created_at)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
        );
        $statement->execute([
            'client_id' => $assignment->clientId(),
            'pricing_group_id' => $assignment->pricingGroupId(),
            'visitor_ref_hash' => $assignment->visitorRefHash(),
            'price_list_id' => $assignment->priceListId(),
            'assigned_at' => $assignment->assignedAt()->format(self::DT),
            'created_at' => $assignment->createdAt()->format(self::DT),
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $existing = $this->findById($id);
        \assert($existing !== null);

        return $existing;
    }

    public function reassign(int $id, int $priceListId, DateTimeImmutable $reassignedAt): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE price_list_assignments SET price_list_id = :price_list_id, reassigned_at = :reassigned_at, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            'id' => $id,
            'price_list_id' => $priceListId,
            'reassigned_at' => $reassignedAt->format(self::DT),
            'updated_at' => $reassignedAt->format(self::DT),
        ]);
    }

    private function findById(int $id): ?PriceListAssignment
    {
        $statement = $this->pdo->prepare('SELECT * FROM price_list_assignments WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    private function hydrate(mixed $row): ?PriceListAssignment
    {
        if (!\is_array($row)) {
            return null;
        }

        $reassignedAt = Row::nullableStr($row['reassigned_at'] ?? null);
        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);

        return PriceListAssignment::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::int($row['pricing_group_id'] ?? null),
            Row::str($row['visitor_ref_hash'] ?? ''),
            Row::int($row['price_list_id'] ?? null),
            new DateTimeImmutable(Row::str($row['assigned_at'] ?? 'now')),
            $reassignedAt !== null ? new DateTimeImmutable($reassignedAt) : null,
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
