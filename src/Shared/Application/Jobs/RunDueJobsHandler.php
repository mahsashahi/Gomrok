<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Jobs;

use Gomrok\Shared\Application\ErrorLog\ErrorLogEntry;
use Gomrok\Shared\Application\ErrorLog\ErrorLogLevel;
use Gomrok\Shared\Application\ErrorLog\ErrorLogWriter;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Shared\Domain\Jobs\JobRepository;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * The one piece of "worker" logic Phase 29 needs regardless of how it's
 * invoked (Q5, still pending — cron-batch vs a daemon loop both just call
 * this the same way): claim a batch of due jobs, dispatch each to its
 * registered handler, record the outcome, and reschedule a recurring type's
 * next occurrence. A handler that throws is caught and logged
 * (`ErrorLogWriter`, `source: 'job'`) rather than allowed to abort the whole
 * batch — one bad job must never block every other due job behind it.
 */
final readonly class RunDueJobsHandler
{
    /**
     * @param list<JobHandler> $handlers
     */
    public function __construct(
        private JobRepository $jobs,
        private array $handlers,
        private ErrorLogWriter $errorLog,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{attempted: int, succeeded: int, failed: int, unhandled: int}
     */
    public function run(int $limit, string $lockedBy): array
    {
        $summary = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'unhandled' => 0];

        // Self-bootstrapping: a recurring type with no row yet (first ever
        // run, or one just added in code) gets seeded due immediately.
        // A no-op once a pending/processing row already exists.
        foreach ($this->handlers as $handler) {
            if ($handler->recurrenceIntervalMinutes() !== null) {
                $this->jobs->ensureScheduled($handler->type(), $this->clock->now(), $this->clock->now());
            }
        }

        $claimed = $this->jobs->claimDue($limit, $lockedBy, $this->clock->now());
        foreach ($claimed as $job) {
            ++$summary['attempted'];
            $this->dispatch($job, $summary);
        }

        return $summary;
    }

    /**
     * Admin "run now" (`jobs.retry`): claims and runs one specific job
     * immediately, bypassing its `run_at`. Returns `null` if the job
     * doesn't exist or isn't `pending` (already running, or a one-off
     * that's already finished) — distinct from `false`, which means the job
     * genuinely ran but its handler reported failure.
     */
    public function runOne(int $jobId, string $lockedBy): ?bool
    {
        $job = $this->jobs->claimById($jobId, $lockedBy, $this->clock->now());
        if ($job === null) {
            return null;
        }

        $summary = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'unhandled' => 0];
        $this->dispatch($job, $summary);

        return $summary['succeeded'] > 0;
    }

    /**
     * @param array{attempted: int, succeeded: int, failed: int, unhandled: int} $summary
     */
    private function dispatch(Job $job, array &$summary): void
    {
        $handler = $this->handlerFor($job->type());
        if ($handler === null) {
            $job->deadLetter("No handler registered for job type '{$job->type()}'.", $this->clock->now());
            $this->jobs->save($job);
            ++$summary['unhandled'];

            return;
        }

        $alreadyLogged = false;
        try {
            $result = $handler->handle($job);
        } catch (Throwable $e) {
            $this->errorLog->log(ErrorLogEntry::fromThrowable(
                $e,
                'job',
                context: ['job_type' => $job->type(), 'job_id' => $job->id()],
            ));
            $alreadyLogged = true;
            $result = JobRunResult::failure($e->getMessage());
        }

        $interval = $handler->recurrenceIntervalMinutes();
        $nextRunAt = $interval !== null ? $this->clock->now()->modify("+{$interval} minutes") : null;

        if ($result->success) {
            $job->recordSuccess($this->encode($result->summary), $nextRunAt, $this->clock->now());
            ++$summary['succeeded'];
        } else {
            $error = $result->error ?? 'unknown error';
            // A handler that returns JobRunResult::failure() directly (rather than
            // throwing) isn't caught above — log it here too, so per-occurrence
            // failure history for debugging always lands in the existing Error Logs
            // screen regardless of which path a handler used to report failure. Guarded
            // by $alreadyLogged so a thrown exception isn't logged twice.
            if (!$alreadyLogged) {
                $this->errorLog->log(new ErrorLogEntry(
                    ErrorLogLevel::Error,
                    'job',
                    $error,
                    context: ['job_type' => $job->type(), 'job_id' => $job->id()],
                ));
            }
            $job->recordFailure($error, $nextRunAt, $this->clock->now());
            ++$summary['failed'];
        }

        $this->jobs->save($job);
    }

    private function handlerFor(string $type): ?JobHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->type() === $type) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * @param array<string, scalar> $summary
     */
    private function encode(array $summary): ?string
    {
        if ($summary === []) {
            return null;
        }

        $json = json_encode($summary, \JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : null;
    }
}
