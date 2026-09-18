<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Domain\Jobs;

use DateTimeImmutable;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Shared\Domain\Jobs\JobStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JobTest extends TestCase
{
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-17T12:00:00+00:00');
    }

    #[Test]
    public function scheduleStartsPendingWithZeroAttemptsAndNoId(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());

        self::assertNull($job->id());
        self::assertSame(JobStatus::Pending, $job->status());
        self::assertSame(0, $job->attempts());
        self::assertNull($job->lockedAt());
        self::assertNull($job->lockedBy());
        self::assertNull($job->updatedAt());
    }

    #[Test]
    public function claimMovesToProcessingAndIncrementsAttempts(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());
        $claimedAt = $this->now()->modify('+1 minute');

        $job->claim('worker-1', $claimedAt);

        self::assertSame(JobStatus::Processing, $job->status());
        self::assertSame(1, $job->attempts());
        self::assertSame($claimedAt, $job->lockedAt());
        self::assertSame('worker-1', $job->lockedBy());
        self::assertSame($claimedAt, $job->updatedAt());
    }

    #[Test]
    public function recordSuccessWithoutANextRunAtLeavesItDoneAndTerminal(): void
    {
        $job = Job::schedule('one_off', null, $this->now(), $this->now());
        $job->claim('worker-1', $this->now());

        $job->recordSuccess('{"ok":true}', null, $this->now());

        self::assertSame(JobStatus::Done, $job->status());
        self::assertSame('{"ok":true}', $job->lastResult());
        self::assertNull($job->lastError());
        self::assertNull($job->lockedAt());
        self::assertNull($job->lockedBy());
    }

    #[Test]
    public function recordSuccessWithANextRunAtReschedulesBackToPending(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());
        $job->claim('worker-1', $this->now());
        $nextRunAt = $this->now()->modify('+5 minutes');

        $job->recordSuccess(null, $nextRunAt, $this->now());

        self::assertSame(JobStatus::Pending, $job->status());
        self::assertSame($nextRunAt, $job->runAt());
        self::assertNull($job->lockedAt());
    }

    #[Test]
    public function recordFailureWithoutANextRunAtLeavesItFailedAndTerminal(): void
    {
        $job = Job::schedule('one_off', null, $this->now(), $this->now());
        $job->claim('worker-1', $this->now());

        $job->recordFailure('boom', null, $this->now());

        self::assertSame(JobStatus::Failed, $job->status());
        self::assertSame('boom', $job->lastError());
        self::assertNull($job->lockedAt());
    }

    #[Test]
    public function recordFailureWithANextRunAtReschedulesDespiteTheFailure(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());
        $job->claim('worker-1', $this->now());
        $nextRunAt = $this->now()->modify('+5 minutes');

        $job->recordFailure('boom', $nextRunAt, $this->now());

        self::assertSame(JobStatus::Pending, $job->status());
        self::assertSame($nextRunAt, $job->runAt());
        self::assertSame('boom', $job->lastError());
    }

    #[Test]
    public function deadLetterIsAlwaysTerminalRegardlessOfRecurrence(): void
    {
        $job = Job::schedule('one_off', null, $this->now(), $this->now());
        $job->claim('worker-1', $this->now());

        $job->deadLetter('no handler', $this->now());

        self::assertSame(JobStatus::DeadLettered, $job->status());
        self::assertSame('no handler', $job->lastError());
        self::assertNull($job->lockedAt());
        self::assertNull($job->lockedBy());
    }

    #[Test]
    public function assignIdSetsTheIdOnce(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());
        $job->assignId(9);

        self::assertSame(9, $job->id());
    }

    #[Test]
    public function fromStorageRoundTripsEveryField(): void
    {
        $failedAt = $this->now();
        $successAt = $this->now()->modify('-1 day');
        $alertedAt = $this->now()->modify('-2 hours');
        $ackAt = $this->now()->modify('-1 hour');

        $job = Job::fromStorage(
            9,
            'webhook_retry_scan',
            '{"foo":"bar"}',
            JobStatus::Failed,
            3,
            2,
            10,
            $failedAt,
            $successAt,
            $alertedAt,
            $ackAt,
            5,
            $this->now(),
            $this->now(),
            'worker-1',
            'boom',
            null,
            $this->now(),
            $this->now(),
        );

        self::assertSame(9, $job->id());
        self::assertSame('webhook_retry_scan', $job->type());
        self::assertSame('{"foo":"bar"}', $job->payload());
        self::assertSame(JobStatus::Failed, $job->status());
        self::assertSame(3, $job->attempts());
        self::assertSame(2, $job->consecutiveFailures());
        self::assertSame(10, $job->totalFailures());
        self::assertSame($failedAt, $job->lastFailedAt());
        self::assertSame($successAt, $job->lastSuccessAt());
        self::assertSame($alertedAt, $job->alertedAt());
        self::assertSame($ackAt, $job->alertAcknowledgedAt());
        self::assertSame(5, $job->alertAcknowledgedBy());
        self::assertSame('worker-1', $job->lockedBy());
        self::assertSame('boom', $job->lastError());
    }

    #[Test]
    public function recordFailureIncrementsConsecutiveAndTotalFailures(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());
        $failedAt = $this->now();

        $job->recordFailure('boom', $this->now()->modify('+5 minutes'), $failedAt);

        self::assertSame(1, $job->consecutiveFailures());
        self::assertSame(1, $job->totalFailures());
        self::assertSame($failedAt, $job->lastFailedAt());
        self::assertNull($job->alertedAt());
    }

    #[Test]
    public function alertIsRaisedOnceConsecutiveFailuresCrossTheThreshold(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());

        $job->recordFailure('boom 1', $this->now()->modify('+5 minutes'), $this->now());
        self::assertNull($job->alertedAt());

        $job->recordFailure('boom 2', $this->now()->modify('+5 minutes'), $this->now());
        self::assertNull($job->alertedAt());

        $thirdFailureAt = $this->now()->modify('+15 minutes');
        $job->recordFailure('boom 3', $this->now()->modify('+5 minutes'), $thirdFailureAt);

        self::assertSame(3, $job->consecutiveFailures());
        self::assertSame(3, $job->totalFailures());
        self::assertSame($thirdFailureAt, $job->alertedAt());
        self::assertTrue($job->hasOpenAlert());
        self::assertTrue($job->isAlertUnacknowledged());
    }

    #[Test]
    public function aFourthConsecutiveFailureDoesNotResetOrReRaiseTheAlert(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());
        $job->recordFailure('boom 1', $this->now(), $this->now());
        $job->recordFailure('boom 2', $this->now(), $this->now());
        $alertedAt = $this->now()->modify('+15 minutes');
        $job->recordFailure('boom 3', $this->now(), $alertedAt);

        $job->recordFailure('boom 4', $this->now(), $this->now()->modify('+20 minutes'));

        self::assertSame(4, $job->consecutiveFailures());
        self::assertSame($alertedAt, $job->alertedAt(), 'alertedAt must not move on a subsequent failure — avoids alert spam');
    }

    #[Test]
    public function recordSuccessResetsConsecutiveFailuresAndClearsTheAlert(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());
        $job->recordFailure('boom 1', $this->now(), $this->now());
        $job->recordFailure('boom 2', $this->now(), $this->now());
        $job->recordFailure('boom 3', $this->now(), $this->now());
        self::assertNotNull($job->alertedAt());
        $job->acknowledgeAlert(5, $this->now());

        $successAt = $this->now()->modify('+1 hour');
        $job->recordSuccess(null, $this->now()->modify('+5 minutes'), $successAt);

        self::assertSame(0, $job->consecutiveFailures());
        self::assertSame(3, $job->totalFailures(), 'total_failures never resets');
        self::assertSame($successAt, $job->lastSuccessAt());
        self::assertNull($job->alertedAt());
        self::assertNull($job->alertAcknowledgedAt());
        self::assertNull($job->alertAcknowledgedBy());
        self::assertFalse($job->hasOpenAlert());
    }

    #[Test]
    public function acknowledgeAlertIsANoOpWhenThereIsNoOpenAlert(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());

        $acknowledged = $job->acknowledgeAlert(5, $this->now());

        self::assertFalse($acknowledged);
    }

    #[Test]
    public function acknowledgeAlertIsIdempotent(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());
        $job->recordFailure('boom 1', $this->now(), $this->now());
        $job->recordFailure('boom 2', $this->now(), $this->now());
        $job->recordFailure('boom 3', $this->now(), $this->now());

        $first = $job->acknowledgeAlert(5, $this->now());
        $second = $job->acknowledgeAlert(9, $this->now()->modify('+1 minute'));

        self::assertTrue($first);
        self::assertFalse($second);
        self::assertSame(5, $job->alertAcknowledgedBy(), 'the second call must not overwrite who acknowledged it first');
    }

    #[Test]
    public function anAcknowledgedAlertIsNoLongerUnacknowledgedButStaysOpen(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());
        $job->recordFailure('boom 1', $this->now(), $this->now());
        $job->recordFailure('boom 2', $this->now(), $this->now());
        $job->recordFailure('boom 3', $this->now(), $this->now());

        $job->acknowledgeAlert(5, $this->now());

        self::assertTrue($job->hasOpenAlert());
        self::assertFalse($job->isAlertUnacknowledged());
    }
}
