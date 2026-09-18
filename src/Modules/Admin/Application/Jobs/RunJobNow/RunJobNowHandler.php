<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Jobs\RunJobNow;

use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Jobs\RunDueJobsHandler;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;

/**
 * `jobs.retry` — runs one specific job immediately rather than waiting for
 * its schedule. Only a `pending` job is eligible (checked inside
 * {@see RunDueJobsHandler::runOne()}).
 */
final readonly class RunJobNowHandler
{
    public function __construct(
        private RunDueJobsHandler $worker,
        private AuditLogWriter $audit,
    ) {
    }

    public function handle(RunJobNowCommand $command): Result
    {
        $succeeded = $this->worker->runOne($command->jobId, 'admin:' . $command->actorId);
        if ($succeeded === null) {
            return Result::err(DomainError::conflict(
                'job.not_runnable',
                'This job could not be run now — it may not exist, or may not be pending.',
            ));
        }

        // $succeeded === false means the job genuinely ran but its handler
        // reported failure — that's a valid outcome (visible via last_error
        // on the row), not a reason to reject the admin action itself.
        $this->audit->record(
            AuditEntry::forAdminUser($command->actorId, null, 'job.run_now')
                ->withTarget('job', $command->jobId),
        );

        return Result::ok(null);
    }
}
