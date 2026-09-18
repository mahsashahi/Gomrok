<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `client_notification_logs` — Phase 28. One row per outbound status-change
 * callback Gomrok owes a client (server-to-server; not an in-app/UI
 * notification). `target_type`/`target_id` is a polymorphic reference
 * (`payment` | `subscription`), the same unenforced-FK shape already used by
 * `audit_logs`/`error_logs` — a real FK can't target two different tables.
 *
 * `endpoint_url` and `payload` are snapshots taken at enqueue time (Phase 28
 * Q5's "no duplicate notifications" + the project's general snapshot
 * philosophy): a later change to the client's registered URL, or a retry, never
 * alters what was already decided to be sent. `next_attempt_at` drives the
 * retry job under Q4's exponential backoff (1m/5m/30m/2h/6h/12h/24h/24h, 8
 * attempts, then `dead_lettered`).
 */
final class CreateClientNotificationLogsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('client_notification_logs', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('target_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('target_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('purpose', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('status_value', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('endpoint_url', 'string', ['limit' => 2048, 'null' => false])
            ->addColumn('payload', 'text', ['null' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'pending'])
            ->addColumn('attempt_count', 'smallinteger', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('next_attempt_at', 'datetime', ['null' => true])
            ->addColumn('last_attempted_at', 'datetime', ['null' => true])
            ->addColumn('last_response_status', 'smallinteger', ['signed' => false, 'null' => true])
            ->addColumn('last_response_body', 'text', ['null' => true])
            ->addColumn('last_error', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['client_id', 'status', 'next_attempt_at'], ['name' => 'idx_cnl_retry_scan'])
            ->addIndex(['target_type', 'target_id'], ['name' => 'idx_cnl_target'])
            ->addIndex(['client_id'], ['name' => 'idx_cnl_client'])
            ->addForeignKey(
                'client_id',
                'clients',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_cnl_client_id'],
            )
            ->addForeignKey(
                'provider_account_id',
                'provider_accounts',
                'id',
                ['delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_cnl_provider_account_id'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('client_notification_logs')->drop()->save();
    }
}
