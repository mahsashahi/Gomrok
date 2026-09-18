<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Application\Jobs;

use Gomrok\Shared\Application\Jobs\JobRunResult;
use Gomrok\Shared\Application\Jobs\RunDueJobsHandler;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Shared\Domain\Jobs\JobStatus;
use Gomrok\Tests\Support\FakeJobHandler;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryJobRepository;
use Gomrok\Tests\Support\RecordingErrorLogWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RunDueJobsHandlerTest extends TestCase
{
    private InMemoryJobRepository $jobs;
    private RecordingErrorLogWriter $errorLog;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryJobRepository();
        $this->errorLog = new RecordingErrorLogWriter();
        $this->clock = new FrozenClock('2026-09-17T12:00:00+00:00');
    }

    /**
     * @param list<FakeJobHandler> $handlers
     */
    private function worker(array $handlers): RunDueJobsHandler
    {
        return new RunDueJobsHandler($this->jobs, $handlers, $this->errorLog, $this->clock);
    }

    #[Test]
    public function selfBootstrapsARecurringHandlerWithNoExistingRow(): void
    {
        $handler = new FakeJobHandler('webhook_retry_scan', 5, JobRunResult::success(['scanned' => 0]));

        $summary = $this->worker([$handler])->run(50, 'worker-1');

        self::assertSame(['attempted' => 1, 'succeeded' => 1, 'failed' => 0, 'unhandled' => 0], $summary);
        self::assertSame([1], $handler->handledJobIds);
    }

    #[Test]
    public function aOneOffTypeIsNeverSelfBootstrapped(): void
    {
        $handler = new FakeJobHandler('one_off', null, JobRunResult::success());

        $summary = $this->worker([$handler])->run(50, 'worker-1');

        self::assertSame(['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'unhandled' => 0], $summary);
        self::assertSame([], $handler->handledJobIds);
    }

    #[Test]
    public function aSuccessfulRecurringJobReschedulesItselfPending(): void
    {
        $handler = new FakeJobHandler('webhook_retry_scan', 5, JobRunResult::success(['scanned' => 3]));

        $this->worker([$handler])->run(50, 'worker-1');

        $job = $this->jobs->findById(1);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Pending, $job->status());
        self::assertSame('2026-09-17 12:05:00', $job->runAt()->format('Y-m-d H:i:s'));
        self::assertSame('{"scanned":3}', $job->lastResult());
    }

    #[Test]
    public function aFailedRecurringJobStillReschedulesDespiteTheFailure(): void
    {
        $handler = new FakeJobHandler('webhook_retry_scan', 5, JobRunResult::failure('boom'));

        $summary = $this->worker([$handler])->run(50, 'worker-1');

        self::assertSame(1, $summary['failed']);
        $job = $this->jobs->findById(1);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Pending, $job->status());
        self::assertSame('boom', $job->lastError());
    }

    #[Test]
    public function aReturnedFailureIsAlsoLoggedToErrorLogsForDebuggingHistory(): void
    {
        $handler = new FakeJobHandler('webhook_retry_scan', 5, JobRunResult::failure('boom'));

        $this->worker([$handler])->run(50, 'worker-1');

        self::assertCount(1, $this->errorLog->entries);
        self::assertSame('job', $this->errorLog->entries[0]->source);
        self::assertSame('boom', $this->errorLog->entries[0]->message);
    }

    #[Test]
    public function aRecurringFailureNeverDeadLettersAndKeepsReschedulingAtTheFixedInterval(): void
    {
        $handler = new FakeJobHandler('webhook_retry_scan', 5, JobRunResult::failure('boom'));
        $worker = $this->worker([$handler]);

        for ($i = 0; $i < 10; ++$i) {
            $worker->run(50, 'worker-1');
            $this->clock->advanceSeconds(301);
        }

        $job = $this->jobs->findById(1);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Pending, $job->status(), 'a recurring job must never dead-letter from repeated failure');
        self::assertSame(10, $job->consecutiveFailures());
        self::assertSame(10, $job->totalFailures());
    }

    #[Test]
    public function aRecurringJobRaisesAnAlertOnceConsecutiveFailuresCrossTheThreshold(): void
    {
        $handler = new FakeJobHandler('webhook_retry_scan', 5, JobRunResult::failure('boom'));
        $worker = $this->worker([$handler]);

        $worker->run(50, 'worker-1');
        $this->clock->advanceSeconds(301);
        $worker->run(50, 'worker-1');
        self::assertNull($this->jobs->findById(1)?->alertedAt());

        $this->clock->advanceSeconds(301);
        $worker->run(50, 'worker-1');

        self::assertNotNull($this->jobs->findById(1)?->alertedAt());
    }

    #[Test]
    public function aThrowingHandlerIsCaughtLoggedOnceAndCountedAsFailed(): void
    {
        $handler = new FakeJobHandler('webhook_retry_scan', 5, new RuntimeException('kaboom'));

        $summary = $this->worker([$handler])->run(50, 'worker-1');

        self::assertSame(1, $summary['failed']);
        self::assertCount(1, $this->errorLog->entries, 'a thrown exception must be logged exactly once, not duplicated by the returned-failure path');
        self::assertSame('job', $this->errorLog->entries[0]->source);
        $job = $this->jobs->findById(1);
        self::assertNotNull($job);
        self::assertSame('kaboom', $job->lastError());
    }

    #[Test]
    public function aJobWithNoRegisteredHandlerIsDeadLetteredAndDoesNotAbortTheBatch(): void
    {
        $this->jobs->save(Job::schedule('mystery_type', null, $this->clock->now(), $this->clock->now()));
        $handler = new FakeJobHandler('webhook_retry_scan', 5, JobRunResult::success());

        $summary = $this->worker([$handler])->run(50, 'worker-1');

        self::assertSame(1, $summary['unhandled']);
        self::assertSame(1, $summary['succeeded']);
        $mystery = $this->jobs->findById(1);
        self::assertNotNull($mystery);
        self::assertSame(JobStatus::DeadLettered, $mystery->status());
    }

    #[Test]
    public function oneBadJobDoesNotBlockOtherDueJobsBehindIt(): void
    {
        $failing = new FakeJobHandler('a_type', 5, new RuntimeException('boom'));
        $succeeding = new FakeJobHandler('b_type', 5, JobRunResult::success());

        $summary = $this->worker([$failing, $succeeding])->run(50, 'worker-1');

        self::assertSame(2, $summary['attempted']);
        self::assertSame(1, $summary['succeeded']);
        self::assertSame(1, $summary['failed']);
    }

    #[Test]
    public function runOneReturnsNullForAnUnknownJob(): void
    {
        $result = $this->worker([])->runOne(999, 'worker-1');

        self::assertNull($result);
    }

    #[Test]
    public function runOneReturnsTrueAndRunsAPendingJobImmediately(): void
    {
        $handler = new FakeJobHandler('webhook_retry_scan', 5, JobRunResult::success());
        $this->jobs->save(Job::schedule('webhook_retry_scan', null, $this->clock->now()->modify('+1 hour'), $this->clock->now()));

        $result = $this->worker([$handler])->runOne(1, 'admin:1');

        self::assertTrue($result);
        self::assertSame([1], $handler->handledJobIds);
    }

    #[Test]
    public function runOneReturnsFalseWhenTheHandlerReportsFailure(): void
    {
        $handler = new FakeJobHandler('webhook_retry_scan', 5, JobRunResult::failure('boom'));
        $this->jobs->save(Job::schedule('webhook_retry_scan', null, $this->clock->now(), $this->clock->now()));

        $result = $this->worker([$handler])->runOne(1, 'admin:1');

        self::assertFalse($result);
    }

    #[Test]
    public function runOneReturnsNullForAJobThatIsNotPending(): void
    {
        $handler = new FakeJobHandler('webhook_retry_scan', 5, JobRunResult::success());
        $job = Job::schedule('webhook_retry_scan', null, $this->clock->now(), $this->clock->now());
        $job->claim('someone-else', $this->clock->now());
        $this->jobs->save($job);

        $result = $this->worker([$handler])->runOne(1, 'admin:1');

        self::assertNull($result);
        self::assertSame([], $handler->handledJobIds);
    }
}
