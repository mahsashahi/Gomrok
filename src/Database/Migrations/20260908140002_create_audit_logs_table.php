<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `audit_logs` — append-only trail of sensitive admin / system actions
 * (provider-config changes, secret rotation, refunds, retries, pricing / voucher
 * / country-override edits, permission changes). `before` / `after` hold the
 * full target row as JSON with secret-bearing columns redacted; `before` is null
 * on create, `after` is null on delete.
 *
 * No `updated_at` — rows are never modified after insert.
 */
final class CreateAuditLogsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('audit_logs', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('actor_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('actor_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('action', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('target_type', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('target_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('before', 'json', ['null' => true])
            ->addColumn('after', 'json', ['null' => true])
            ->addColumn('context', 'json', ['null' => true])
            ->addColumn('correlation_id', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['client_id', 'created_at'], ['name' => 'idx_audit_logs_client_created'])
            ->addIndex(['target_type', 'target_id'], ['name' => 'idx_audit_logs_target'])
            ->addIndex(['action'], ['name' => 'idx_audit_logs_action'])
            ->create();
    }

    public function down(): void
    {
        $this->table('audit_logs')->drop()->save();
    }
}
