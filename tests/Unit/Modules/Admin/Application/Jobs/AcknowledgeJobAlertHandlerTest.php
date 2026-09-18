<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Jobs;

use Gomrok\Modules\Admin\Application\Jobs\AcknowledgeJobAlert\AcknowledgeJobAlertCommand;
use Gomrok\Modules\Admin\Application\Jobs\AcknowledgeJobAlert\AcknowledgeJobAlertHandler;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryJobRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcknowledgeJobAlertHandlerTest extends TestCase
{
    private InMemoryJobRepository $jobs;
    private RecordingAuditLogWriter $audit;
    private FrozenClock $clock;
    private AcknowledgeJobAlertHandler $handler;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryJobRepository();
        $this->audit = new RecordingAuditLogWriter();
        $this->clock = new FrozenClock('2026-09-18T12:00:00+00:00');
        $this->handler = new AcknowledgeJobAlertHandler($this->jobs, $this->audit, $this->clock);
    }

    private function seedAlertingJob(): int
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->clock->now(), $this->clock->now());
        for ($i = 0; $i < 3; ++$i) {
            $job->claim('worker-1', $this->clock->now());
            $job->recordFailure('boom', null, $this->clock->now());
        }
        $this->jobs->save($job);
        $id = $job->id();
        \assert($id !== null);

        return $id;
    }

    #[Test]
    public function acknowledgesAnOpenAlertAndRecordsAnAuditEntry(): void
    {
        $jobId = $this->seedAlertingJob();

        $result = $this->handler->handle(new AcknowledgeJobAlertCommand($jobId, actorId: 5));

        self::assertTrue($result->isOk());
        $job = $this->jobs->findById($jobId);
        self::assertNotNull($job);
        self::assertTrue($job->hasOpenAlert());
        self::assertFalse($job->isAlertUnacknowledged());
        self::assertSame(5, $job->alertAcknowledgedBy());
        self::assertSame(['job.alert_acknowledged'], $this->audit->actions());
    }

    #[Test]
    public function isIdempotentOnAnAlreadyAcknowledgedAlert(): void
    {
        $jobId = $this->seedAlertingJob();
        $this->handler->handle(new AcknowledgeJobAlertCommand($jobId, actorId: 5));

        $result = $this->handler->handle(new AcknowledgeJobAlertCommand($jobId, actorId: 9));

        self::assertTrue($result->isOk());
        self::assertSame(['job.alert_acknowledged'], $this->audit->actions());
        self::assertSame(5, $this->jobs->findById($jobId)?->alertAcknowledgedBy());
    }

    #[Test]
    public function isANoOpWhenThereIsNoOpenAlert(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->clock->now(), $this->clock->now());
        $this->jobs->save($job);
        $jobId = $job->id();
        \assert($jobId !== null);

        $result = $this->handler->handle(new AcknowledgeJobAlertCommand($jobId, actorId: 5));

        self::assertTrue($result->isOk());
        self::assertSame([], $this->audit->actions());
    }

    #[Test]
    public function rejectsAnUnknownJob(): void
    {
        $result = $this->handler->handle(new AcknowledgeJobAlertCommand(999, actorId: 5));

        self::assertTrue($result->isErr());
        self::assertSame('job.not_found', $result->error()->code);
    }
}
