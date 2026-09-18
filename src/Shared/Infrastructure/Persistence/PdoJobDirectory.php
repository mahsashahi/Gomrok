<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Persistence;

use DateTimeImmutable;
use Gomrok\Shared\Application\Jobs\JobDirectory;
use Gomrok\Shared\Application\Jobs\JobFilter;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Shared\Domain\Jobs\JobStatus;
use PDO;

final readonly class PdoJobDirectory implements JobDirectory
{
    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?Job
    {
        $statement = $this->pdo->prepare('SELECT * FROM jobs WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    public function search(JobFilter $filter): array
    {
        [$where, $params] = $this->whereClause($filter);

        $statement = $this->pdo->prepare(
            "SELECT * FROM jobs {$where} ORDER BY id DESC LIMIT :limit OFFSET :offset",
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

    public function countMatching(JobFilter $filter): int
    {
        [$where, $params] = $this->whereClause($filter);

        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM jobs {$where}");
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    public function countAlerting(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM jobs WHERE alerted_at IS NOT NULL');
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function distinctTypes(): array
    {
        $statement = $this->pdo->prepare('SELECT DISTINCT type FROM jobs ORDER BY type');
        $statement->execute();

        $out = [];
        while (($type = $statement->fetchColumn()) !== false) {
            $out[] = (string) $type;
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: array<string, scalar>}
     */
    private function whereClause(JobFilter $filter): array
    {
        $conditions = [];
        $params = [];

        if ($filter->status !== null) {
            $conditions[] = 'status = :status';
            $params['status'] = $filter->status;
        }
        if ($filter->type !== null) {
            $conditions[] = 'type = :type';
            $params['type'] = $filter->type;
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $params];
    }

    private function hydrate(mixed $row): Job
    {
        \assert(\is_array($row));

        $dt = static fn (mixed $value): ?DateTimeImmutable => Row::nullableStr($value) !== null ? new DateTimeImmutable(Row::str($value)) : null;

        return Job::fromStorage(
            Row::int($row['id'] ?? null),
            Row::str($row['type'] ?? ''),
            Row::nullableStr($row['payload'] ?? null),
            JobStatus::from(Row::str($row['status'] ?? 'pending')),
            Row::int($row['attempts'] ?? null),
            Row::int($row['consecutive_failures'] ?? null),
            Row::int($row['total_failures'] ?? null),
            $dt($row['last_failed_at'] ?? null),
            $dt($row['last_success_at'] ?? null),
            $dt($row['alerted_at'] ?? null),
            $dt($row['alert_acknowledged_at'] ?? null),
            Row::nullableInt($row['alert_acknowledged_by'] ?? null),
            new DateTimeImmutable(Row::str($row['run_at'] ?? 'now')),
            $dt($row['locked_at'] ?? null),
            Row::nullableStr($row['locked_by'] ?? null),
            Row::nullableStr($row['last_error'] ?? null),
            Row::nullableStr($row['last_result'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $dt($row['updated_at'] ?? null),
        );
    }
}
