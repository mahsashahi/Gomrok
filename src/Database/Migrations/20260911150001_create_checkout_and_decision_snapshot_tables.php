<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `checkout_attempts` (Phase 18 Q1/Q2 — new `Checkout` module) is the anchor for
 * the whole pre-payment lifecycle: `attempt_reference` is the external,
 * caller-supplied idempotent key; `id` is the internal relational anchor every
 * decision-snapshot table below FKs to. Three write-once decision-snapshot
 * tables, one per owning module (Q4): `pricing_decision_snapshots` (Pricing),
 * `voucher_decision_snapshots` (Vouchers — thin, FKs to `voucher_redemptions`
 * for the immutable amounts), `provider_routing_decision_snapshots`
 * (Providers). See `.claude/Voucher.md` and `.claude/docs/database-design.md`.
 */
final class CreateCheckoutAndDecisionSnapshotTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('checkout_attempts', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('client_user_ref', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('attempt_reference', 'string', ['limit' => 191, 'null' => false])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('country', 'char', ['limit' => 2, 'null' => false])
            ->addColumn('currency_code', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('purchase_type', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('payment_method', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('subscription_interval', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 30, 'null' => false, 'default' => 'started'])
            ->addColumn('error_code', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('error_message', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addColumn('abandoned_at', 'datetime', ['null' => true])
            ->addColumn('expired_at', 'datetime', ['null' => true])
            ->addIndex(['client_id', 'attempt_reference'], ['unique' => true, 'name' => 'uniq_checkout_attempts_client_ref'])
            ->addIndex(['client_id', 'status'], ['name' => 'idx_checkout_attempts_client_status'])
            ->addIndex(['package_id'], ['name' => 'idx_checkout_attempts_package'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_checkout_attempts_client'])
            ->addForeignKey('package_id', 'packages', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_checkout_attempts_package'])
            ->addForeignKey('country', 'countries', 'code', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_checkout_attempts_country'])
            ->addForeignKey('currency_code', 'currencies', 'code', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_checkout_attempts_currency'])
            ->create();

        $this->table('pricing_decision_snapshots', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('checkout_attempt_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('currency_code', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('amount_minor', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('source', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('payload', 'json', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['checkout_attempt_id'], ['unique' => true, 'name' => 'uniq_pricing_decision_snapshots_attempt'])
            ->addIndex(['client_id', 'package_id'], ['name' => 'idx_pricing_decision_snapshots_client_package'])
            ->addForeignKey('checkout_attempt_id', 'checkout_attempts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_pricing_decision_snapshots_attempt'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_pricing_decision_snapshots_client'])
            ->addForeignKey('package_id', 'packages', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_pricing_decision_snapshots_package'])
            ->addForeignKey('currency_code', 'currencies', 'code', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_pricing_decision_snapshots_currency'])
            ->create();

        $this->table('voucher_decision_snapshots', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('checkout_attempt_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('voucher_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('voucher_redemption_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('voucher_code', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('voucher_name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['checkout_attempt_id'], ['unique' => true, 'name' => 'uniq_voucher_decision_snapshots_attempt'])
            ->addIndex(['voucher_redemption_id'], ['unique' => true, 'name' => 'uniq_voucher_decision_snapshots_redemption'])
            ->addIndex(['client_id', 'voucher_id'], ['name' => 'idx_voucher_decision_snapshots_client_voucher'])
            ->addForeignKey('checkout_attempt_id', 'checkout_attempts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_voucher_decision_snapshots_attempt'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_voucher_decision_snapshots_client'])
            ->addForeignKey('voucher_id', 'vouchers', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_voucher_decision_snapshots_voucher'])
            ->addForeignKey('voucher_redemption_id', 'voucher_redemptions', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_voucher_decision_snapshots_redemption'])
            ->create();

        $this->table('provider_routing_decision_snapshots', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('checkout_attempt_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('payment_method', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('purchase_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('payload', 'json', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['checkout_attempt_id'], ['unique' => true, 'name' => 'uniq_provider_routing_decision_snapshots_attempt'])
            ->addIndex(['client_id', 'provider_account_id'], ['name' => 'idx_provider_routing_decision_snapshots_client_account'])
            ->addForeignKey('checkout_attempt_id', 'checkout_attempts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_routing_decision_snapshots_attempt'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_routing_decision_snapshots_client'])
            ->addForeignKey('provider_account_id', 'provider_accounts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_routing_decision_snapshots_account'])
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_routing_decision_snapshots')->drop()->save();
        $this->table('voucher_decision_snapshots')->drop()->save();
        $this->table('pricing_decision_snapshots')->drop()->save();
        $this->table('checkout_attempts')->drop()->save();
    }
}
