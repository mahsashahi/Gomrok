<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Jobs\AcknowledgeJobAlert;

use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Jobs\JobRepository;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * `jobs.retry` — an admin silencing a still-failing recurring job's alert
 * (Phase 29 Q5, revised). Idempotent: acknowledging a job with no open
 * alert, or one already acknowledged, is a no-op — same convention
 * {@see \Gomrok\Modules\Reconciliation\Application\ResolveReconciliationFinding\ResolveReconciliationFindingHandler}
 * uses. Acknowledging never touches the job's schedule or status — the
 * recurring job keeps running exactly as before; only its alert visibility
 * changes.
 */
final readonly class AcknowledgeJobAlertHandler
{
    public function __construct(
        private JobRepository $jobs,
        private AuditLogWriter $audit,
        private ClockInterface $clock,
    ) {
    }

    public function handle(AcknowledgeJobAlertCommand $command): Result
    {
        $job = $this->jobs->findById($command->jobId);
        if ($job === null) {
            return Result::err(DomainError::notFound('job.not_found', "Job {$command->jobId} was not found."));
        }

        $acknowledged = $job->acknowledgeAlert($command->actorId, $this->clock->now());
        if (!$acknowledged) {
            return Result::ok(null);
        }

        $this->jobs->save($job);

        $this->audit->record(
            AuditEntry::forAdminUser($command->actorId, null, 'job.alert_acknowledged')
                ->withTarget('job', $command->jobId),
        );

        return Result::ok(null);
    }
}
