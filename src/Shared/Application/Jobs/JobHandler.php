<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Jobs;

use Gomrok\Shared\Domain\Jobs\Job;

/**
 * One background-work handler, registered with {@see RunDueJobsHandler} via
 * DI (mirrors {@see \Gomrok\Shared\Application\Events\DomainEventSubscriber}'s
 * registration shape). A module owns the handlers for the job types it's
 * responsible for.
 */
interface JobHandler
{
    /** The `jobs.type` value this handler processes. */
    public function type(): string;

    /**
     * Minutes until this job type's next occurrence should run, or `null`
     * if it's a one-off (never reschedules regardless of outcome).
     */
    public function recurrenceIntervalMinutes(): ?int;

    public function handle(Job $job): JobRunResult;
}
