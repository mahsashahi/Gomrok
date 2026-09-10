<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `pricing_group_packages` — per `(pricing group, package)` (Phase 13 Q3). No
 * row = implicit `status = default` (baseline, converted if needed).
 * `status = override` carries its own `amount_minor` + `currency_code` (= the
 * group currency); `status = disabled` hides the package in that group.
 * `name_override` / `badge_override` / `highlighted_override` refine the display;
 * `display_order` is the customer-facing position.
 */
final class CreatePricingGroupPackagesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pricing_group_packages', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('pricing_group_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'default'])
            ->addColumn('amount_minor', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('currency_code', 'char', ['limit' => 3, 'null' => true])
            ->addColumn('name_override', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('badge_override', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('highlighted_override', 'boolean', ['null' => true])
            ->addColumn('display_order', 'smallinteger', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['pricing_group_id', 'package_id'], ['unique' => true, 'name' => 'uniq_pricing_group_packages'])
            ->addIndex(['pricing_group_id', 'display_order'], ['name' => 'idx_pricing_group_packages_order'])
            ->addIndex(['package_id'], ['name' => 'idx_pricing_group_packages_package'])
            ->addForeignKey(
                'pricing_group_id',
                'pricing_groups',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_pricing_group_packages_group'],
            )
            ->addForeignKey(
                'package_id',
                'packages',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_pricing_group_packages_package'],
            )
            ->addForeignKey(
                'currency_code',
                'currencies',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_pricing_group_packages_currency'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('pricing_group_packages')->drop()->save();
    }
}
