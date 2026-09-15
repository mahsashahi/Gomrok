<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * `webhook_events` — Phase 25. Every inbound provider webhook is stored here
 * before any processing is attempted (CLAUDE.md: "Each webhook event must be
 * stored before processing to support idempotency, debugging, and replay
 * protection"). `event_id` / `event_type` / `raw_status` are nullable because
 * a signature-verification failure never reaches the parse step.
 *
 * Dedup key (Phase 25 Q2): `(provider_account_id, event_id, raw_status)` — not
 * `event_id` alone, because Mollie's webhooks carry the payment id as the
 * "event id" for every status change on that payment, so `raw_status` must be
 * part of the key or every status change after the first would be dropped as
 * a false duplicate.
 *
 * `status` (Phase 25 Q3, user-specified): received -> processing ->
 * processed | retry_pending -> failed. `max_attempts` is a code constant
 * ({@see \Gomrok\Modules\Webhooks\Application\ProcessWebhookEvent\ProcessWebhookEventHandler}),
 * not a column.
 */
final class CreateWebhookEventsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('webhook_events', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('provider_type_code', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('event_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('event_type', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('raw_status', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('provider_reference', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('raw_payload', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false])
            ->addColumn('headers', 'json', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'received'])
            ->addColumn('attempt_count', 'smallinteger', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('error_code', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('error_message', 'text', ['null' => true])
            ->addColumn('payment_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('processed_at', 'datetime', ['null' => true])
            ->addColumn('last_attempted_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['provider_account_id', 'event_id', 'raw_status'], ['unique' => true, 'name' => 'uniq_webhook_events_dedup'])
            ->addIndex(['status'], ['name' => 'idx_webhook_events_status'])
            ->addIndex(['client_id'], ['name' => 'idx_webhook_events_client'])
            ->addIndex(['payment_id'], ['name' => 'idx_webhook_events_payment'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_webhook_events_client'])
            ->addForeignKey('provider_account_id', 'provider_accounts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_webhook_events_provider_account'])
            ->addForeignKey('payment_id', 'payments', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_webhook_events_payment'])
            ->create();
    }

    public function down(): void
    {
        $this->table('webhook_events')->drop()->save();
    }
}
