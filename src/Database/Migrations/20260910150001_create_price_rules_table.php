<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `price_rules` — layered price / availability overrides for a `(client,
 * package)` (Phase 14). Seven **wildcard-nullable** dimensions; a rule applies
 * when every non-null dimension equals the request's value. The most-specific
 * matching rule wins (count of matched dimensions → fixed dimension priority →
 * highest id). `is_available = 0` marks a combination as not sold — the
 * resolver returns `pricing.combination_unavailable`, never a fallback price.
 */
final class CreatePriceRulesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('price_rules', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('pricing_group_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('country_code', 'char', ['limit' => 2, 'null' => true])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('payment_method', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('purchase_type', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('subscription_interval', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('currency_code', 'char', ['limit' => 3, 'null' => true])
            ->addColumn('is_available', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('amount_minor', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(
                ['package_id', 'pricing_group_id', 'country_code', 'provider_account_id', 'payment_method', 'purchase_type', 'subscription_interval', 'currency_code'],
                ['unique' => true, 'name' => 'uniq_price_rules_dimensions'],
            )
            ->addIndex(['client_id', 'package_id'], ['name' => 'idx_price_rules_client_package'])
            ->addIndex(['provider_account_id'], ['name' => 'idx_price_rules_provider_account'])
            ->addIndex(['pricing_group_id'], ['name' => 'idx_price_rules_pricing_group'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_price_rules_client'])
            ->addForeignKey('package_id', 'packages', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_price_rules_package'])
            ->addForeignKey('pricing_group_id', 'pricing_groups', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_price_rules_pricing_group'])
            ->addForeignKey('country_code', 'countries', 'code', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_price_rules_country'])
            ->addForeignKey('provider_account_id', 'provider_accounts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_price_rules_provider_account'])
            ->addForeignKey('currency_code', 'currencies', 'code', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_price_rules_currency'])
            ->create();
    }

    public function down(): void
    {
        $this->table('price_rules')->drop()->save();
    }
}
