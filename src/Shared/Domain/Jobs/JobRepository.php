<?php

declare(strict_types=1);

namespace Gomrok\Shared\Domain\Jobs;

/**
 * Persistence port for {@see Job}.
 */
interface JobRepository
{
    public function save(Job $job): void;

    public function findById(int $id): ?Job;

    /**
     * Atomically claims up to `$limit` due jobs (`status = pending`,
     * `run_at <= now`) for `$lockedBy`, so two overlapping worker
     * invocations can never process the same row (`SELECT ... FOR UPDATE
     * SKIP LOCKED` under the hood). Returned jobs are already marked
     * `processing` and saved.
     *
     * @return list<Job>
     */
    public function claimDue(int $limit, string $lockedBy, \DateTimeImmutable $now): array;

    /**
     * Atomically claims one specific job by id, bypassing its `run_at` —
     * the admin "run now" action. Returns `null` if the job doesn't exist
     * or isn't `pending`.
     */
    public function claimById(int $id, string $lockedBy, \DateTimeImmutable $now): ?Job;

    /**
     * Enqueues the next occurrence of a recurring job type — used both by
     * a handler rescheduling itself and to seed a job type that has never
     * run yet. A no-op if a `pending` or `processing` row of this type
     * already exists (idempotent seeding).
     */
    public function ensureScheduled(string $type, \DateTimeImmutable $runAt, \DateTimeImmutable $now): void;
}
