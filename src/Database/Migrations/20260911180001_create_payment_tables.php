<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * The `Payments` module (Phase 20): `payments` is created from exactly one
 * confirmed `checkout_attempts` row (Q1, `UNIQUE (checkout_attempt_id)`).
 * `payment_attempts` (one row per distinct "try" against a provider) and
 * `provider_transactions` (one immutable row per raw provider call/response
 * under an attempt) are a deliberate three-tier model (Q3) — CLAUDE.md names
 * `payments`, `payment attempts`, and `provider transactions` as three
 * distinct Required Database Concepts. `provider_customers` and
 * `gateway_references` (Q4) are the durable-customer-identity and generic
 * reverse-lookup tables the Gateway Reference Lookup Rule calls for. See
 * `.claude/docs/database-design.md`.
 */
final class CreatePaymentTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('payments', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('checkout_attempt_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('client_user_ref', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('country', 'char', ['limit' => 2, 'null' => false])
            ->addColumn('currency_code', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('amount_minor', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('purchase_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('payment_method', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('subscription_interval', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'created'])
            ->addColumn('error_code', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('error_message', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['checkout_attempt_id'], ['unique' => true, 'name' => 'uniq_payments_checkout_attempt'])
            ->addIndex(['client_id', 'status'], ['name' => 'idx_payments_client_status'])
            ->addIndex(['package_id'], ['name' => 'idx_payments_package'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_payments_client'])
            ->addForeignKey('checkout_attempt_id', 'checkout_attempts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_payments_checkout_attempt'])
            ->addForeignKey('package_id', 'packages', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_payments_package'])
            ->addForeignKey('country', 'countries', 'code', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_payments_country'])
            ->addForeignKey('currency_code', 'currencies', 'code', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_payments_currency'])
            ->create();

        $this->table('payment_attempts', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('payment_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('attempt_number', 'smallinteger', ['signed' => false, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'started'])
            ->addColumn('payment_method', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('error_code', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('error_message', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['payment_id', 'attempt_number'], ['unique' => true, 'name' => 'uniq_payment_attempts_number'])
            ->addIndex(['provider_account_id'], ['name' => 'idx_payment_attempts_provider_account'])
            ->addForeignKey('payment_id', 'payments', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_payment_attempts_payment'])
            ->addForeignKey('provider_account_id', 'provider_accounts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_payment_attempts_provider_account'])
            ->create();

        $this->table('provider_transactions', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('payment_attempt_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('kind', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('request_payload', 'json', ['null' => true])
            ->addColumn('response_payload', 'json', ['null' => true])
            ->addColumn('provider_status_raw', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['payment_attempt_id'], ['name' => 'idx_provider_transactions_attempt'])
            ->addForeignKey('payment_attempt_id', 'payment_attempts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_transactions_attempt'])
            ->create();

        $this->table('provider_customers', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('client_user_ref', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('provider_customer_id', 'string', ['limit' => 191, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['provider_account_id', 'provider_customer_id'], ['unique' => true, 'name' => 'uniq_provider_customers_account_ref'])
            ->addIndex(['client_id', 'client_user_ref'], ['name' => 'idx_provider_customers_client_user'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_customers_client'])
            ->addForeignKey('provider_account_id', 'provider_accounts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_customers_account'])
            ->create();

        $this->table('gateway_references', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('reference_type', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('reference_value', 'string', ['limit' => 191, 'null' => false])
            ->addColumn('payment_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['provider_account_id', 'reference_type', 'reference_value'], ['unique' => true, 'name' => 'uniq_gateway_references_account_type_value'])
            ->addIndex(['payment_id'], ['name' => 'idx_gateway_references_payment'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_gateway_references_client'])
            ->addForeignKey('provider_account_id', 'provider_accounts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_gateway_references_account'])
            ->addForeignKey('payment_id', 'payments', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_gateway_references_payment'])
            ->create();
    }

    public function down(): void
    {
        $this->table('gateway_references')->drop()->save();
        $this->table('provider_customers')->drop()->save();
        $this->table('provider_transactions')->drop()->save();
        $this->table('payment_attempts')->drop()->save();
        $this->table('payments')->drop()->save();
    }
}
