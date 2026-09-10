<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `price_lists` + `price_list_packages` — A/B price experiments inside a pricing
 * group (Phase 15). Every group has exactly one **control** row (`is_control = 1`,
 * `factor = 1.0000`, always enabled, undeletable); non-control lists shift the
 * base price by `factor`, and `price_list_packages` can pin an exact amount for a
 * single package on that list.
 *
 * The migration backfills a control row for every existing pricing group.
 *
 * Visitor -> list assignment (the `price_list_assignments` table) is deliberately
 * NOT part of this phase — deferred to the payment/checkout phase (Phase 15 Q4/Q5).
 */
final class CreatePriceListsTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('price_lists', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('pricing_group_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('is_control', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('factor', 'decimal', ['precision' => 6, 'scale' => 4, 'null' => false, 'default' => '1.0000'])
            ->addColumn('is_enabled', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['pricing_group_id', 'name'], ['unique' => true, 'name' => 'uniq_price_lists_group_name'])
            ->addIndex(['client_id', 'pricing_group_id'], ['name' => 'idx_price_lists_client_group'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_price_lists_client'])
            ->addForeignKey('pricing_group_id', 'pricing_groups', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_price_lists_group'])
            ->create();

        $this->table('price_list_packages', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('price_list_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('amount_minor', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('currency_code', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['price_list_id', 'package_id'], ['unique' => true, 'name' => 'uniq_price_list_packages'])
            ->addIndex(['package_id'], ['name' => 'idx_price_list_packages_package'])
            ->addForeignKey('price_list_id', 'price_lists', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_price_list_packages_list'])
            ->addForeignKey('package_id', 'packages', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_price_list_packages_package'])
            ->addForeignKey('currency_code', 'currencies', 'code', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_price_list_packages_currency'])
            ->create();

        // Backfill: one control list per existing pricing group.
        $this->execute(
            "INSERT INTO price_lists (client_id, pricing_group_id, name, is_control, factor, is_enabled, created_at)
             SELECT client_id, id, 'List A · control', 1, 1.0000, 1, UTC_TIMESTAMP() FROM pricing_groups",
        );
    }

    public function down(): void
    {
        $this->table('price_list_packages')->drop()->save();
        $this->table('price_lists')->drop()->save();
    }
}
