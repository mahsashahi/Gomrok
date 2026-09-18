<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Jobs;

use Gomrok\Shared\Domain\Jobs\Job;

/**
 * Published read API over `jobs` — the admin Jobs screen's only reader.
 */
interface JobDirectory
{
    public function find(int $id): ?Job;

    /**
     * Newest-scheduled first.
     *
     * @return list<Job>
     */
    public function search(JobFilter $filter): array;

    public function countMatching(JobFilter $filter): int;

    /**
     * Jobs currently showing an open alert (Phase 29 Q5, revised) —
     * acknowledged or not, since an acknowledged-but-still-failing job is
     * still worth counting as "needs attention" at a glance.
     */
    public function countAlerting(): int;

    /**
     * Every distinct `type` value ever recorded — for the screen's filter
     * dropdown, alphabetical.
     *
     * @return list<string>
     */
    public function distinctTypes(): array;
}
