<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Shared\Application\Jobs\JobDirectory;
use Gomrok\Shared\Application\Jobs\JobFilter;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Shared\Domain\Jobs\JobRepository;
use Gomrok\Shared\Domain\Jobs\JobStatus;

/**
 * Doubles as both the write port ({@see JobRepository}) and the read port
 * ({@see JobDirectory}) — same shape as
 * {@see InMemoryReconciliationFindingRepository}: entities are stored by
 * reference, so a mutation (e.g. {@see Job::claim()}) is visible to every
 * reader without an explicit "replace" call.
 */
final class InMemoryJobRepository implements JobRepository, JobDirectory
{
    /** @var array<int, Job> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(Job $job): void
    {
        if ($job->id() === null) {
            $job->assignId($this->nextId++);
        }
        $id = $job->id();
        \assert($id !== null);
        $this->byId[$id] = $job;
    }

    public function findById(int $id): ?Job
    {
        return $this->byId[$id] ?? null;
    }

    public function claimDue(int $limit, string $lockedBy, \DateTimeImmutable $now): array
    {
        $due = array_values(array_filter(
            $this->byId,
            static fn (Job $j): bool => $j->status() === JobStatus::Pending && $j->runAt() <= $now,
        ));
        usort($due, static fn (Job $a, Job $b): int => $a->runAt() <=> $b->runAt());
        $due = \array_slice($due, 0, $limit);

        foreach ($due as $job) {
            $job->claim($lockedBy, $now);
        }

        return $due;
    }

    public function claimById(int $id, string $lockedBy, \DateTimeImmutable $now): ?Job
    {
        $job = $this->byId[$id] ?? null;
        if ($job === null || $job->status() !== JobStatus::Pending) {
            return null;
        }
        $job->claim($lockedBy, $now);

        return $job;
    }

    public function ensureScheduled(string $type, \DateTimeImmutable $runAt, \DateTimeImmutable $now): void
    {
        foreach ($this->byId as $job) {
            if ($job->type() === $type && \in_array($job->status(), [JobStatus::Pending, JobStatus::Processing], true)) {
                return;
            }
        }

        $this->save(Job::schedule($type, null, $runAt, $now));
    }

    /**
     * @return list<Job>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }

    public function find(int $id): ?Job
    {
        return $this->findById($id);
    }

    public function search(JobFilter $filter): array
    {
        $matching = array_values(array_filter($this->byId, fn (Job $j): bool => $this->matches($j, $filter)));
        usort($matching, static fn (Job $a, Job $b): int => ($b->id() ?? 0) <=> ($a->id() ?? 0));

        return \array_slice($matching, $filter->offset, $filter->limit);
    }

    public function countMatching(JobFilter $filter): int
    {
        return \count(array_filter($this->byId, fn (Job $j): bool => $this->matches($j, $filter)));
    }

    public function countAlerting(): int
    {
        return \count(array_filter($this->byId, static fn (Job $j): bool => $j->hasOpenAlert()));
    }

    public function distinctTypes(): array
    {
        $types = array_values(array_unique(array_map(static fn (Job $j): string => $j->type(), $this->byId)));
        sort($types);

        return $types;
    }

    private function matches(Job $job, JobFilter $filter): bool
    {
        if ($filter->status !== null && $job->status()->value !== $filter->status) {
            return false;
        }

        return $filter->type === null || $job->type() === $filter->type;
    }
}
