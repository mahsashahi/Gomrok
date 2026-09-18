<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Shared\Application\Jobs\JobHandler;
use Gomrok\Shared\Application\Jobs\JobRunResult;
use Gomrok\Shared\Domain\Jobs\Job;
use Throwable;

/**
 * A configurable {@see JobHandler} double — `outcome` is either a
 * {@see JobRunResult} to return, or a {@see Throwable} to throw, letting a
 * test drive every branch of {@see \Gomrok\Shared\Application\Jobs\RunDueJobsHandler::dispatch()}.
 */
final class FakeJobHandler implements JobHandler
{
    /** @var list<int> */
    public array $handledJobIds = [];

    public function __construct(
        private readonly string $jobType,
        private readonly ?int $recurrenceIntervalMinutes,
        private readonly JobRunResult|Throwable $outcome,
    ) {
    }

    public function type(): string
    {
        return $this->jobType;
    }

    public function recurrenceIntervalMinutes(): ?int
    {
        return $this->recurrenceIntervalMinutes;
    }

    public function handle(Job $job): JobRunResult
    {
        $this->handledJobIds[] = $job->id() ?? 0;

        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}
