<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `jobs` health-tracking columns (Phase 29 Q5, revised). A recurring job
 * never dead-letters from repeated failure and never gets escalating
 * backoff (user-specified: reschedule stays at the handler's fixed interval
 * forever) — but its health must still be visible to an operator instead of
 * failing silently forever.
 *
 * `consecutive_failures` resets to 0 on success and drives the alert
 * threshold (`Job::ALERT_THRESHOLD`, a code constant — not per-job-type or
 * DB-configurable, since that wasn't asked for). `total_failures` never
 * resets — lifetime count for this row. `alerted_at` is set once when
 * `consecutive_failures` first crosses the threshold and stays set while
 * still failing, so a new alert isn't raised on every subsequent failure;
 * it only clears on the next success. `alert_acknowledged_at`/`_by` let an
 * admin silence an still-failing job's alert without it immediately
 * re-firing on the next failure — only a real recovery (success) clears
 * both `alerted_at` and the acknowledgement together. No FK on
 * `alert_acknowledged_by`, matching `error_logs.resolved_by` /
 * `reconciliation_findings.resolved_by`'s existing unconstrained convention.
 *
 * Per-occurrence failure detail for debugging is NOT duplicated here —
 * `RunDueJobsHandler` now also logs a handler's returned
 * `JobRunResult::failure()` (not just a thrown exception) to the existing
 * `error_logs` table (`source: 'job'`), so history lives in the Error Logs
 * screen already built for exactly this purpose.
 */
final class AddHealthTrackingToJobsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('jobs')
            ->addColumn('consecutive_failures', 'smallinteger', ['signed' => false, 'null' => false, 'default' => 0, 'after' => 'attempts'])
            ->addColumn('total_failures', 'integer', ['signed' => false, 'null' => false, 'default' => 0, 'after' => 'consecutive_failures'])
            ->addColumn('last_failed_at', 'datetime', ['null' => true, 'after' => 'total_failures'])
            ->addColumn('last_success_at', 'datetime', ['null' => true, 'after' => 'last_failed_at'])
            ->addColumn('alerted_at', 'datetime', ['null' => true, 'after' => 'last_success_at'])
            ->addColumn('alert_acknowledged_at', 'datetime', ['null' => true, 'after' => 'alerted_at'])
            ->addColumn('alert_acknowledged_by', 'integer', ['signed' => false, 'null' => true, 'after' => 'alert_acknowledged_at'])
            ->addIndex(['alerted_at'], ['name' => 'idx_jobs_alerted'])
            ->update();
    }

    public function down(): void
    {
        $this->table('jobs')
            ->removeIndexByName('idx_jobs_alerted')
            ->removeColumn('alert_acknowledged_by')
            ->removeColumn('alert_acknowledged_at')
            ->removeColumn('alerted_at')
            ->removeColumn('last_success_at')
            ->removeColumn('last_failed_at')
            ->removeColumn('total_failures')
            ->removeColumn('consecutive_failures')
            ->update();
    }
}
