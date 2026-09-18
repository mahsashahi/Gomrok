<?php

declare(strict_types=1);

namespace Gomrok\Modules\Reconciliation\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFinding;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFindingRepository;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationTargetType;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoReconciliationFindingRepository implements ReconciliationFindingRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(ReconciliationFinding $finding): void
    {
        if ($finding->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO reconciliation_findings
                    (client_id, target_type, target_id, local_status, provider_status_raw, mapped_provider_status,
                     detected_at, resolved_at, resolved_by, created_at)
                 VALUES
                    (:client_id, :target_type, :target_id, :local_status, :provider_status_raw, :mapped_provider_status,
                     :detected_at, :resolved_at, :resolved_by, :created_at)',
            );
            $statement->execute($this->bindings($finding));
            $finding->assignId((int) $this->pdo->lastInsertId());

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE reconciliation_findings SET resolved_at = :resolved_at, resolved_by = :resolved_by WHERE id = :id',
        );
        $statement->execute([
            'id' => $finding->id(),
            'resolved_at' => $finding->resolvedAt()?->format(self::DT),
            'resolved_by' => $finding->resolvedBy(),
        ]);
    }

    public function findById(int $id): ?ReconciliationFinding
    {
        $statement = $this->pdo->prepare('SELECT * FROM reconciliation_findings WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    /**
     * @return array<string, mixed>
     */
    private function bindings(ReconciliationFinding $finding): array
    {
        return [
            'client_id' => $finding->clientId(),
            'target_type' => $finding->targetType()->value,
            'target_id' => $finding->targetId(),
            'local_status' => $finding->localStatus(),
            'provider_status_raw' => $finding->providerStatusRaw(),
            'mapped_provider_status' => $finding->mappedProviderStatus(),
            'detected_at' => $finding->detectedAt()->format(self::DT),
            'resolved_at' => $finding->resolvedAt()?->format(self::DT),
            'resolved_by' => $finding->resolvedBy(),
            'created_at' => $finding->createdAt()->format(self::DT),
        ];
    }

    private function hydrate(mixed $row): ?ReconciliationFinding
    {
        if (!\is_array($row)) {
            return null;
        }

        $resolvedAt = Row::nullableStr($row['resolved_at'] ?? null);

        return ReconciliationFinding::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            ReconciliationTargetType::from(Row::str($row['target_type'] ?? 'payment')),
            Row::int($row['target_id'] ?? null),
            Row::str($row['local_status'] ?? ''),
            Row::str($row['provider_status_raw'] ?? ''),
            Row::nullableStr($row['mapped_provider_status'] ?? null),
            new DateTimeImmutable(Row::str($row['detected_at'] ?? 'now')),
            $resolvedAt !== null ? new DateTimeImmutable($resolvedAt) : null,
            Row::nullableInt($row['resolved_by'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
        );
    }
}
