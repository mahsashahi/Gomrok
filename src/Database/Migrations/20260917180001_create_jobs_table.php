<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `jobs` — Phase 29 Q1 (user-specified) — the unified DB-backed background-work
 * queue. Every recurring task (webhook retry, notification retry, the two new
 * sweeps, Mollie renewal charging, both reconciliation scans) is a
 * self-rescheduling row here: when a handler finishes, it enqueues its own next
 * occurrence rather than relying on a separate cron-schedule table. `type` is a
 * fixed, code-defined set — no lookup table.
 *
 * Claimed via `SELECT ... FOR UPDATE SKIP LOCKED` (MySQL 8) on `(status,
 * run_at)`, so two overlapping worker invocations can't double-process the
 * same row. No `client_id` — every job type this phase introduces is a global
 * scan across clients, not a per-client entity.
 */
final class CreateJobsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('jobs', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('type', 'string', ['limit' => 60, 'null' => false])
            ->addColumn('payload', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'pending'])
            ->addColumn('attempts', 'smallinteger', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('run_at', 'datetime', ['null' => false])
            ->addColumn('locked_at', 'datetime', ['null' => true])
            ->addColumn('locked_by', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('last_error', 'text', ['null' => true])
            ->addColumn('last_result', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['status', 'run_at'], ['name' => 'idx_jobs_claim_scan'])
            ->addIndex(['type'], ['name' => 'idx_jobs_type'])
            ->create();
    }

    public function down(): void
    {
        $this->table('jobs')->drop()->save();
    }
}
