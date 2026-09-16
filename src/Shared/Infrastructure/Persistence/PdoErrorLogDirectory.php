<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Persistence;

use Gomrok\Shared\Application\ErrorLog\ErrorLogDirectory;
use Gomrok\Shared\Application\ErrorLog\ErrorLogFilter;
use Gomrok\Shared\Application\ErrorLog\ErrorLogRecord;
use PDO;

final readonly class PdoErrorLogDirectory implements ErrorLogDirectory
{
    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?ErrorLogRecord
    {
        $statement = $this->pdo->prepare('SELECT * FROM error_logs WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    public function search(ErrorLogFilter $filter): array
    {
        [$where, $params] = $this->whereClause($filter);

        $statement = $this->pdo->prepare(
            "SELECT * FROM error_logs {$where} ORDER BY id DESC LIMIT :limit OFFSET :offset",
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

    public function countMatching(ErrorLogFilter $filter): int
    {
        [$where, $params] = $this->whereClause($filter);

        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM error_logs {$where}");
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    public function distinctSources(): array
    {
        $statement = $this->pdo->prepare('SELECT DISTINCT source FROM error_logs ORDER BY source');
        $statement->execute();

        $out = [];
        while (($source = $statement->fetchColumn()) !== false) {
            $out[] = (string) $source;
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: array<string, scalar>}
     */
    private function whereClause(ErrorLogFilter $filter): array
    {
        $conditions = [];
        $params = [];

        if ($filter->level !== null) {
            $conditions[] = 'level = :level';
            $params['level'] = $filter->level;
        }
        if ($filter->source !== null) {
            $conditions[] = 'source = :source';
            $params['source'] = $filter->source;
        }
        if ($filter->clientId !== null) {
            $conditions[] = 'client_id = :client_id';
            $params['client_id'] = $filter->clientId;
        }
        if ($filter->resolved === true) {
            $conditions[] = 'resolved_at IS NOT NULL';
        } elseif ($filter->resolved === false) {
            $conditions[] = 'resolved_at IS NULL';
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $params];
    }

    private function hydrate(mixed $row): ErrorLogRecord
    {
        \assert(\is_array($row));

        return new ErrorLogRecord(
            Row::int($row['id'] ?? null),
            Row::str($row['level'] ?? ''),
            Row::str($row['source'] ?? ''),
            Row::str($row['message'] ?? ''),
            Row::nullableStr($row['exception_class'] ?? null),
            Row::nullableStr($row['code'] ?? null),
            Row::nullableInt($row['client_id'] ?? null),
            Row::nullableStr($row['correlation_id'] ?? null),
            $this->decode($row['context'] ?? null),
            Row::nullableStr($row['stack_trace'] ?? null),
            Row::str($row['created_at'] ?? ''),
            Row::nullableStr($row['resolved_at'] ?? null),
            Row::nullableInt($row['resolved_by'] ?? null),
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
