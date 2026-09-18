<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Jobs;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Jobs\JobsFilterState;
use Gomrok\Modules\Admin\Application\Jobs\JobsScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Shared\Domain\Jobs\JobStatus;
use Gomrok\Tests\Support\InMemoryAdminUserRepository;
use Gomrok\Tests\Support\InMemoryJobRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JobsScreenHandlerTest extends TestCase
{
    private InMemoryJobRepository $jobs;
    private InMemoryAdminUserRepository $adminUsers;
    private JobsScreenHandler $handler;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryJobRepository();
        $this->adminUsers = new InMemoryAdminUserRepository();
        $this->handler = new JobsScreenHandler($this->jobs, $this->adminUsers);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-17T12:00:00+00:00');
    }

    #[Test]
    public function defaultsToShowingEveryStatus(): void
    {
        $this->jobs->save(Job::schedule('webhook_retry_scan', null, $this->now(), $this->now()));
        $failing = Job::schedule('notification_retry_scan', null, $this->now(), $this->now());
        $failing->claim('worker-1', $this->now());
        $failing->recordFailure('boom', null, $this->now());
        $this->jobs->save($failing);

        $result = $this->handler->build(new JobsFilterState(), 1);

        self::assertCount(2, $result->rows);
    }

    #[Test]
    public function filtersByStatus(): void
    {
        $this->jobs->save(Job::schedule('webhook_retry_scan', null, $this->now(), $this->now()));
        $failing = Job::schedule('notification_retry_scan', null, $this->now(), $this->now());
        $failing->claim('worker-1', $this->now());
        $failing->recordFailure('boom', null, $this->now());
        $this->jobs->save($failing);

        $result = $this->handler->build(new JobsFilterState(status: 'failed'), 1);

        self::assertCount(1, $result->rows);
        self::assertSame('notification_retry_scan', $result->rows[0]->type);
    }

    #[Test]
    public function theFailedCountIgnoresTheStatusFilterItself(): void
    {
        $failing = Job::schedule('notification_retry_scan', null, $this->now(), $this->now());
        $failing->claim('worker-1', $this->now());
        $failing->recordFailure('boom', null, $this->now());
        $this->jobs->save($failing);

        $result = $this->handler->build(new JobsFilterState(status: 'pending'), 1);

        self::assertSame(1, $result->failedCount);
    }

    #[Test]
    public function onlyAPendingJobIsRunnableNow(): void
    {
        $this->jobs->save(Job::schedule('webhook_retry_scan', null, $this->now(), $this->now()));
        $processing = Job::schedule('notification_retry_scan', null, $this->now(), $this->now());
        $processing->claim('worker-1', $this->now());
        $this->jobs->save($processing);

        $result = $this->handler->build(new JobsFilterState(), 1);

        $byType = [];
        foreach ($result->rows as $row) {
            $byType[$row->type] = $row->isRunnableNow;
        }
        self::assertTrue($byType['webhook_retry_scan']);
        self::assertFalse($byType['notification_retry_scan']);
    }

    #[Test]
    public function exposesTheDistinctTypeListForTheFilterDropdown(): void
    {
        $this->jobs->save(Job::schedule('webhook_retry_scan', null, $this->now(), $this->now()));
        $this->jobs->save(Job::schedule('notification_retry_scan', null, $this->now(), $this->now()));

        $result = $this->handler->build(new JobsFilterState(), 1);

        self::assertSame(['notification_retry_scan', 'webhook_retry_scan'], $result->availableTypes);
    }

    #[Test]
    public function theAlertingCountIgnoresTheStatusFilterAndReflectsOpenAlerts(): void
    {
        $alerting = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());
        for ($i = 0; $i < 3; ++$i) {
            $alerting->claim('worker-1', $this->now());
            $alerting->recordFailure('boom', null, $this->now());
        }
        $this->jobs->save($alerting);
        $this->jobs->save(Job::schedule('notification_retry_scan', null, $this->now(), $this->now()));

        $result = $this->handler->build(new JobsFilterState(status: 'pending'), 1);

        self::assertSame(1, $result->alertingCount);
    }

    #[Test]
    public function resolvesTheAcknowledgingAdminToAName(): void
    {
        $admin = AdminUser::create('Jane Doe', 'jane@example.com', 'hash', AdminRole::Admin, $this->now());
        $this->adminUsers->save($admin);
        $adminId = $admin->id() ?? 0;

        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());
        for ($i = 0; $i < 3; ++$i) {
            $job->claim('worker-1', $this->now());
            $job->recordFailure('boom', null, $this->now());
        }
        $job->acknowledgeAlert($adminId, $this->now());
        $this->jobs->save($job);

        $row = $this->handler->build(new JobsFilterState(), 1)->rows[0];

        self::assertTrue($row->hasOpenAlert);
        self::assertFalse($row->isAlertUnacknowledged);
        self::assertSame('Jane Doe', $row->alertAcknowledgedByLabel);
    }

    #[Test]
    public function anUnacknowledgedAlertHasNoAcknowledgerLabel(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now(), $this->now());
        for ($i = 0; $i < 3; ++$i) {
            $job->claim('worker-1', $this->now());
            $job->recordFailure('boom', null, $this->now());
        }
        $this->jobs->save($job);

        $row = $this->handler->build(new JobsFilterState(), 1)->rows[0];

        self::assertTrue($row->hasOpenAlert);
        self::assertTrue($row->isAlertUnacknowledged);
        self::assertNull($row->alertAcknowledgedByLabel);
    }

    #[Test]
    public function paginatesAndClampsAnOutOfRangePage(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->jobs->save(Job::schedule("type_{$i}", null, $this->now(), $this->now()));
        }

        $result = $this->handler->build(new JobsFilterState(), 999);

        self::assertSame(3, $result->totalCount);
        self::assertSame(1, $result->totalPages);
        self::assertSame(1, $result->page);
        self::assertCount(3, $result->rows);
    }
}
