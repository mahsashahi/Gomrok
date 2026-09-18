<?php

declare(strict_types=1);

namespace Gomrok\Modules\Reconciliation\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Reconciliation\Application\ReconciliationFindingDirectory;
use Gomrok\Modules\Reconciliation\Application\ReconciliationFindingFilter;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFinding;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationTargetType;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoReconciliationFindingDirectory implements ReconciliationFindingDirectory
{
    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?ReconciliationFinding
    {
        $statement = $this->pdo->prepare('SELECT * FROM reconciliation_findings WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    public function search(ReconciliationFindingFilter $filter): array
    {
        [$where, $params] = $this->whereClause($filter);

        $statement = $this->pdo->prepare(
            "SELECT * FROM reconciliation_findings {$where} ORDER BY id DESC LIMIT :limit OFFSET :offset",
        );
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', max(1, min(200, $filter->limit)), PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, $filter->offset), PDO::PARAM_INT);
        $statement->execute();

        $out = [];
        while (($row = $statement->fetch()) !== false) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    public function countMatching(ReconciliationFindingFilter $filter): int
    {
        [$where, $params] = $this->whereClause($filter);

        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM reconciliation_findings {$where}");
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    public function countOpen(?int $clientId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM reconciliation_findings WHERE resolved_at IS NULL';
        $params = [];
        if ($clientId !== null) {
            $sql .= ' AND client_id = :client_id';
            $params['client_id'] = $clientId;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array{0: string, 1: array<string, scalar>}
     */
    private function whereClause(ReconciliationFindingFilter $filter): array
    {
        $conditions = [];
        $params = [];

        if ($filter->clientId !== null) {
            $conditions[] = 'client_id = :client_id';
            $params['client_id'] = $filter->clientId;
        }
        if ($filter->resolution === 'open') {
            $conditions[] = 'resolved_at IS NULL';
        } elseif ($filter->resolution === 'resolved') {
            $conditions[] = 'resolved_at IS NOT NULL';
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $params];
    }

    private function hydrate(mixed $row): ReconciliationFinding
    {
        \assert(\is_array($row));

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
