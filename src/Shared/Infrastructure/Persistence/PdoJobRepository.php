<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Persistence;

use DateTimeImmutable;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Shared\Domain\Jobs\JobRepository;
use Gomrok\Shared\Domain\Jobs\JobStatus;
use PDO;

/**
 * MySQL-backed {@see JobRepository}. `claimDue()` runs in a transaction
 * ({@see TransactionRunner}), same shape as {@see PdoIdempotencyStore}'s own
 * claim-under-lock pattern: `SELECT ... FOR UPDATE SKIP LOCKED` picks rows no
 * other worker has claimed, then an `UPDATE` marks them `processing` before
 * the transaction commits.
 */
final readonly class PdoJobRepository implements JobRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(
        private PDO $pdo,
        private Transactions $transactions,
    ) {
    }

    public function save(Job $job): void
    {
        if ($job->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO jobs
                    (type, payload, status, attempts, consecutive_failures, total_failures, last_failed_at,
                     last_success_at, alerted_at, alert_acknowledged_at, alert_acknowledged_by,
                     run_at, locked_at, locked_by, last_error, last_result, created_at, updated_at)
                 VALUES
                    (:type, :payload, :status, :attempts, :consecutive_failures, :total_failures, :last_failed_at,
                     :last_success_at, :alerted_at, :alert_acknowledged_at, :alert_acknowledged_by,
                     :run_at, :locked_at, :locked_by, :last_error, :last_result, :created_at, :updated_at)',
            );
            $statement->execute($this->bindings($job));
            $job->assignId((int) $this->pdo->lastInsertId());

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE jobs SET
                status = :status, attempts = :attempts, consecutive_failures = :consecutive_failures,
                total_failures = :total_failures, last_failed_at = :last_failed_at, last_success_at = :last_success_at,
                alerted_at = :alerted_at, alert_acknowledged_at = :alert_acknowledged_at,
                alert_acknowledged_by = :alert_acknowledged_by, run_at = :run_at, locked_at = :locked_at,
                locked_by = :locked_by, last_error = :last_error, last_result = :last_result, updated_at = :updated_at
             WHERE id = :id',
        );
        $bindings = $this->bindings($job);
        $statement->execute([
            'id' => $job->id(),
            'status' => $bindings['status'],
            'attempts' => $bindings['attempts'],
            'consecutive_failures' => $bindings['consecutive_failures'],
            'total_failures' => $bindings['total_failures'],
            'last_failed_at' => $bindings['last_failed_at'],
            'last_success_at' => $bindings['last_success_at'],
            'alerted_at' => $bindings['alerted_at'],
            'alert_acknowledged_at' => $bindings['alert_acknowledged_at'],
            'alert_acknowledged_by' => $bindings['alert_acknowledged_by'],
            'run_at' => $bindings['run_at'],
            'locked_at' => $bindings['locked_at'],
            'locked_by' => $bindings['locked_by'],
            'last_error' => $bindings['last_error'],
            'last_result' => $bindings['last_result'],
            'updated_at' => $bindings['updated_at'],
        ]);
    }

    public function findById(int $id): ?Job
    {
        $statement = $this->pdo->prepare('SELECT * FROM jobs WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function claimDue(int $limit, string $lockedBy, DateTimeImmutable $now): array
    {
        return $this->transactions->run(function () use ($limit, $lockedBy, $now): array {
            $select = $this->pdo->prepare(
                'SELECT id FROM jobs
                  WHERE status = :status AND run_at <= :now
                  ORDER BY run_at ASC
                  LIMIT :limit
                  FOR UPDATE SKIP LOCKED',
            );
            $select->bindValue('status', JobStatus::Pending->value);
            $select->bindValue('now', $now->format(self::DT));
            $select->bindValue('limit', $limit, PDO::PARAM_INT);
            $select->execute();

            $ids = [];
            while (($id = $select->fetchColumn()) !== false) {
                $ids[] = (int) $id;
            }

            if ($ids === []) {
                return [];
            }

            $placeholders = implode(', ', array_fill(0, \count($ids), '?'));
            $update = $this->pdo->prepare(
                "UPDATE jobs SET status = ?, attempts = attempts + 1, locked_at = ?, locked_by = ?, updated_at = ?
                 WHERE id IN ({$placeholders})",
            );
            $update->execute([
                JobStatus::Processing->value,
                $now->format(self::DT),
                $lockedBy,
                $now->format(self::DT),
                ...$ids,
            ]);

            $reselect = $this->pdo->prepare("SELECT * FROM jobs WHERE id IN ({$placeholders}) ORDER BY run_at ASC");
            $reselect->execute($ids);

            $jobs = [];
            while (($row = $reselect->fetch()) !== false) {
                $job = $this->hydrate($row);
                if ($job !== null) {
                    $jobs[] = $job;
                }
            }

            return $jobs;
        });
    }

    public function claimById(int $id, string $lockedBy, DateTimeImmutable $now): ?Job
    {
        return $this->transactions->run(function () use ($id, $lockedBy, $now): ?Job {
            $select = $this->pdo->prepare('SELECT * FROM jobs WHERE id = :id AND status = :status FOR UPDATE');
            $select->bindValue('id', $id);
            $select->bindValue('status', JobStatus::Pending->value);
            $select->execute();
            $row = $select->fetch();
            if (!\is_array($row)) {
                return null;
            }

            $update = $this->pdo->prepare(
                'UPDATE jobs SET status = ?, attempts = attempts + 1, locked_at = ?, locked_by = ?, updated_at = ? WHERE id = ?',
            );
            $update->execute([JobStatus::Processing->value, $now->format(self::DT), $lockedBy, $now->format(self::DT), $id]);

            return $this->findById($id);
        });
    }

    public function ensureScheduled(string $type, DateTimeImmutable $runAt, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM jobs WHERE type = :type AND status IN ('pending', 'processing')",
        );
        $statement->execute(['type' => $type]);
        if ((int) $statement->fetchColumn() > 0) {
            return;
        }

        $this->save(Job::schedule($type, null, $runAt, $now));
    }

    /**
     * @return array<string, mixed>
     */
    private function bindings(Job $job): array
    {
        return [
            'type' => $job->type(),
            'payload' => $job->payload(),
            'status' => $job->status()->value,
            'attempts' => $job->attempts(),
            'consecutive_failures' => $job->consecutiveFailures(),
            'total_failures' => $job->totalFailures(),
            'last_failed_at' => $job->lastFailedAt()?->format(self::DT),
            'last_success_at' => $job->lastSuccessAt()?->format(self::DT),
            'alerted_at' => $job->alertedAt()?->format(self::DT),
            'alert_acknowledged_at' => $job->alertAcknowledgedAt()?->format(self::DT),
            'alert_acknowledged_by' => $job->alertAcknowledgedBy(),
            'run_at' => $job->runAt()->format(self::DT),
            'locked_at' => $job->lockedAt()?->format(self::DT),
            'locked_by' => $job->lockedBy(),
            'last_error' => $job->lastError(),
            'last_result' => $job->lastResult(),
            'created_at' => $job->createdAt()->format(self::DT),
            'updated_at' => $job->updatedAt()?->format(self::DT),
        ];
    }

    private function hydrate(mixed $row): ?Job
    {
        if (!\is_array($row)) {
            return null;
        }

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
