<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Persistence;

use Gomrok\Shared\Application\Audit\AuditLogDirectory;
use Gomrok\Shared\Application\Audit\AuditLogEntry;
use Gomrok\Shared\Application\Audit\AuditLogFilter;
use PDO;

final readonly class PdoAuditLogDirectory implements AuditLogDirectory
{
    public function __construct(private PDO $pdo)
    {
    }

    public function search(AuditLogFilter $filter): array
    {
        [$where, $params] = $this->whereClause($filter);

        $statement = $this->pdo->prepare(
            "SELECT * FROM audit_logs {$where} ORDER BY id DESC LIMIT :limit OFFSET :offset",
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

    public function countMatching(AuditLogFilter $filter): int
    {
        [$where, $params] = $this->whereClause($filter);

        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM audit_logs {$where}");
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    public function distinctActions(): array
    {
        $statement = $this->pdo->prepare('SELECT DISTINCT action FROM audit_logs ORDER BY action');
        $statement->execute();

        $out = [];
        while (($action = $statement->fetchColumn()) !== false) {
            $out[] = (string) $action;
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: array<string, scalar>}
     */
    private function whereClause(AuditLogFilter $filter): array
    {
        $conditions = [];
        $params = [];

        if ($filter->actorType !== null) {
            $conditions[] = 'actor_type = :actor_type';
            $params['actor_type'] = $filter->actorType;
        }
        if ($filter->clientId !== null) {
            $conditions[] = 'client_id = :client_id';
            $params['client_id'] = $filter->clientId;
        }
        if ($filter->action !== null) {
            $conditions[] = 'action = :action';
            $params['action'] = $filter->action;
        }
        if ($filter->targetType !== null) {
            $conditions[] = 'target_type = :target_type';
            $params['target_type'] = $filter->targetType;
        }
        if ($filter->targetId !== null) {
            $conditions[] = 'target_id = :target_id';
            $params['target_id'] = $filter->targetId;
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $params];
    }

    private function hydrate(mixed $row): AuditLogEntry
    {
        \assert(\is_array($row));

        return new AuditLogEntry(
            Row::int($row['id'] ?? null),
            Row::str($row['actor_type'] ?? ''),
            Row::nullableInt($row['actor_id'] ?? null),
            Row::nullableInt($row['client_id'] ?? null),
            Row::str($row['action'] ?? ''),
            Row::nullableStr($row['target_type'] ?? null),
            Row::nullableInt($row['target_id'] ?? null),
            $this->decode($row['before'] ?? null),
            $this->decode($row['after'] ?? null),
            $this->decode($row['context'] ?? null),
            Row::nullableStr($row['correlation_id'] ?? null),
            Row::nullableStr($row['ip'] ?? null),
            Row::nullableStr($row['user_agent'] ?? null),
            Row::str($row['created_at'] ?? ''),
        );
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decode(mixed $json): ?array
    {
        if (!\is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : null;
    }
}
