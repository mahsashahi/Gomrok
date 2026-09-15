<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * The `Subscriptions` module (Phase 26). `subscriptions` is created from
 * exactly one confirmed `checkout_attempts` row (Q1 — reuses the Checkout
 * pipeline, the same origin `payments` already requires), and always has a
 * `client_user_ref` (Q4 — mandatory here, unlike `payments.client_user_ref`
 * which stays optional). `subscription_events` is a write-once log (renewal /
 * failed-charge / card-update / status-change), mirroring `provider_transactions`.
 * `subscription_payment_links` ties a subscription to each `payments` row
 * charged under it — including renewal charges, which have no
 * `checkout_attempts` row of their own (Q2; see the companion migration that
 * makes `payments.checkout_attempt_id` nullable).
 */
final class CreateSubscriptionsTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('subscriptions', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('client_user_ref', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('checkout_attempt_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('currency_code', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('amount_minor', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('payment_method', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('subscription_interval', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'active'])
            ->addColumn('trial_ends_at', 'datetime', ['null' => true])
            ->addColumn('current_period_start', 'datetime', ['null' => true])
            ->addColumn('current_period_end', 'datetime', ['null' => true])
            ->addColumn('error_code', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('error_message', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['checkout_attempt_id'], ['unique' => true, 'name' => 'uniq_subscriptions_checkout_attempt'])
            ->addIndex(['client_id', 'client_user_ref'], ['name' => 'idx_subscriptions_client_user'])
            ->addIndex(['client_id', 'status'], ['name' => 'idx_subscriptions_client_status'])
            ->addIndex(['package_id'], ['name' => 'idx_subscriptions_package'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_subscriptions_client'])
            ->addForeignKey('checkout_attempt_id', 'checkout_attempts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_subscriptions_checkout_attempt'])
            ->addForeignKey('package_id', 'packages', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_subscriptions_package'])
            ->addForeignKey('provider_account_id', 'provider_accounts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_subscriptions_provider_account'])
            ->addForeignKey('currency_code', 'currencies', 'code', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_subscriptions_currency'])
            ->create();

        $this->table('subscription_events', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('subscription_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('kind', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('provider_status_raw', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('payload', 'json', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['subscription_id'], ['name' => 'idx_subscription_events_subscription'])
            ->addForeignKey('subscription_id', 'subscriptions', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_subscription_events_subscription'])
            ->create();

        $this->table('subscription_payment_links', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('subscription_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('payment_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('billing_period_start', 'datetime', ['null' => true])
            ->addColumn('billing_period_end', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['payment_id'], ['unique' => true, 'name' => 'uniq_subscription_payment_links_payment'])
            ->addIndex(['subscription_id'], ['name' => 'idx_subscription_payment_links_subscription'])
            ->addForeignKey('subscription_id', 'subscriptions', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_subscription_payment_links_subscription'])
            ->addForeignKey('payment_id', 'payments', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_subscription_payment_links_payment'])
            ->create();
    }

    public function down(): void
    {
        $this->table('subscription_payment_links')->drop()->save();
        $this->table('subscription_events')->drop()->save();
        $this->table('subscriptions')->drop()->save();
    }
}
