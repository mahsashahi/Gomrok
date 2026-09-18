<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Jobs;

use Gomrok\Modules\Admin\Domain\AdminUserRepository;
use Gomrok\Shared\Application\Jobs\JobDirectory;
use Gomrok\Shared\Application\Jobs\JobFilter;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Shared\Domain\Jobs\JobStatus;

/**
 * Builds the admin Jobs screen (Phase 29 — CLAUDE.md: "Retrying failed jobs
 * where safe"). Mirrors {@see \Gomrok\Modules\Admin\Application\Notifications\NotificationsScreenHandler}'s
 * shape. Every job is eligible for "run now" while `pending` — unlike
 * Notifications' dead-letter-only retry, there's no terminal failure state
 * for a recurring job to wait in, so "run now" simply means "don't wait for
 * the schedule."
 */
final readonly class JobsScreenHandler
{
    private const PER_PAGE = 50;
    private const DT = 'Y-m-d H:i';

    public function __construct(
        private JobDirectory $jobs,
        private AdminUserRepository $adminUsers,
    ) {
    }

    public function build(JobsFilterState $filters, int $page): JobsScreenResult
    {
        $status = $filters->status !== 'all' ? $filters->status : null;

        $page = max(1, $page);
        $baseFilter = static fn (int $offset): JobFilter => new JobFilter(
            status: $status,
            type: $filters->type,
            limit: self::PER_PAGE,
            offset: $offset,
        );

        $total = $this->jobs->countMatching($baseFilter(0));
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);

        $entries = $this->jobs->search($baseFilter(($page - 1) * self::PER_PAGE));
        $failedCount = $this->jobs->countMatching(new JobFilter(status: 'failed', type: $filters->type));
        $alertingCount = $this->jobs->countAlerting();

        return new JobsScreenResult(
            array_map($this->toRow(...), $entries),
            $total,
            $failedCount,
            $alertingCount,
            $page,
            self::PER_PAGE,
            $totalPages,
            $this->jobs->distinctTypes(),
            $filters,
        );
    }

    private function toRow(Job $job): JobRow
    {
        return new JobRow(
            $job->id() ?? 0,
            $job->type(),
            $job->status()->value,
            $job->attempts(),
            $job->runAt()->format(self::DT),
            $job->updatedAt()?->format(self::DT),
            $job->lastResult(),
            $job->lastError(),
            $job->status() === JobStatus::Pending,
            $job->consecutiveFailures(),
            $job->totalFailures(),
            $job->lastFailedAt()?->format(self::DT),
            $job->lastSuccessAt()?->format(self::DT),
            $job->hasOpenAlert(),
            $job->isAlertUnacknowledged(),
            $job->alertedAt()?->format(self::DT),
            $job->alertAcknowledgedBy() !== null ? $this->adminLabel($job->alertAcknowledgedBy()) : null,
        );
    }

    private function adminLabel(int $adminUserId): string
    {
        $admin = $this->adminUsers->findById($adminUserId);

        return $admin !== null ? $admin->name() : "Admin user #{$adminUserId}";
    }
}
