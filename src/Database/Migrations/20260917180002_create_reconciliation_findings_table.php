<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `reconciliation_findings` — Phase 29. One row per detected drift between
 * Gomrok's local payment/subscription status and what the provider actually
 * reports when polled. Detection only — reconciliation never silently
 * transitions a payment/subscription itself (a deliberate safety decision,
 * not a schema detail: auto-correcting could apply a status
 * `PaymentStatus::allowedNextStatuses()`/`SubscriptionStatus` would otherwise
 * reject, or mask a real bug instead of surfacing it). `resolved_at`/
 * `resolved_by` is an admin marking a finding reviewed — the same "mark
 * resolved" convention `error_logs` (Phase 27) already uses, not a status
 * transition.
 */
final class CreateReconciliationFindingsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('reconciliation_findings', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('target_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('target_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('local_status', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('provider_status_raw', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('mapped_provider_status', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('detected_at', 'datetime', ['null' => false])
            ->addColumn('resolved_at', 'datetime', ['null' => true])
            ->addColumn('resolved_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['client_id', 'resolved_at'], ['name' => 'idx_reconciliation_findings_client_resolved'])
            ->addIndex(['target_type', 'target_id'], ['name' => 'idx_reconciliation_findings_target'])
            ->addForeignKey(
                'client_id',
                'clients',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_reconciliation_findings_client_id'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('reconciliation_findings')->drop()->save();
    }
}
