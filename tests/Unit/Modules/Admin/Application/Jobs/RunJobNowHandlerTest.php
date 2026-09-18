<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Jobs;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Jobs\RunJobNow\RunJobNowCommand;
use Gomrok\Modules\Admin\Application\Jobs\RunJobNow\RunJobNowHandler;
use Gomrok\Shared\Application\Jobs\JobRunResult;
use Gomrok\Shared\Application\Jobs\RunDueJobsHandler;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Tests\Support\FakeJobHandler;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryJobRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\RecordingErrorLogWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunJobNowHandlerTest extends TestCase
{
    private InMemoryJobRepository $jobs;
    private RecordingAuditLogWriter $audit;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryJobRepository();
        $this->audit = new RecordingAuditLogWriter();
        $this->clock = new FrozenClock('2026-09-17T12:00:00+00:00');
    }

    private function handler(FakeJobHandler $jobHandler): RunJobNowHandler
    {
        $worker = new RunDueJobsHandler($this->jobs, [$jobHandler], new RecordingErrorLogWriter(), $this->clock);

        return new RunJobNowHandler($worker, $this->audit);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-17T12:00:00+00:00');
    }

    #[Test]
    public function runsAPendingJobAndRecordsAnAuditEntry(): void
    {
        $this->jobs->save(Job::schedule('webhook_retry_scan', null, $this->now(), $this->now()));
        $jobHandler = new FakeJobHandler('webhook_retry_scan', 5, JobRunResult::success());

        $result = $this->handler($jobHandler)->handle(new RunJobNowCommand(1, actorId: 5));

        self::assertTrue($result->isOk());
        self::assertSame(['job.run_now'], $this->audit->actions());
        self::assertSame([1], $jobHandler->handledJobIds);
    }

    #[Test]
    public function stillRecordsAnAuditEntryWhenTheHandlerReportsFailure(): void
    {
        $this->jobs->save(Job::schedule('webhook_retry_scan', null, $this->now(), $this->now()));
        $jobHandler = new FakeJobHandler('webhook_retry_scan', 5, JobRunResult::failure('boom'));

        $result = $this->handler($jobHandler)->handle(new RunJobNowCommand(1, actorId: 5));

        self::assertTrue($result->isOk());
        self::assertSame(['job.run_now'], $this->audit->actions());
        $job = $this->jobs->findById(1);
        self::assertNotNull($job);
        self::assertSame('boom', $job->lastError());
    }

    #[Test]
    public function rejectsAnUnknownJobWithoutWritingAnAuditEntry(): void
    {
        $jobHandler = new FakeJobHandler('webhook_retry_scan', 5, JobRunResult::success());

        $result = $this->handler($jobHandler)->handle(new RunJobNowCommand(999, actorId: 5));

        self::assertTrue($result->isErr());
        self::assertSame('job.not_runnable', $result->error()->code);
        self::assertSame([], $this->audit->actions());
    }

    #[Test]
    public function rejectsAJobThatIsAlreadyProcessing(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());
        $job->claim('someone-else', $this->now());
        $this->jobs->save($job);
        $jobHandler = new FakeJobHandler('webhook_retry_scan', 5, JobRunResult::success());

        $result = $this->handler($jobHandler)->handle(new RunJobNowCommand(1, actorId: 5));

        self::assertTrue($result->isErr());
        self::assertSame('job.not_runnable', $result->error()->code);
        self::assertSame([], $jobHandler->handledJobIds);
    }
}
