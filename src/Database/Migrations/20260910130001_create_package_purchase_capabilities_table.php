<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `package_purchase_capabilities` — the purchase types a package supports, with
 * the config that is meaningful for that type (Phase 12 Q1). `purchase_type`
 * holds a `Gomrok\Modules\Providers\Domain\PurchaseType` value (app-enforced).
 * A trial only applies to subscription / recurring_payment (domain-enforced);
 * `duration_months` NULL = open-ended. An empty set for a package = **not
 * sellable** (fail closed).
 */
final class CreatePackagePurchaseCapabilitiesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('package_purchase_capabilities', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('purchase_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('has_trial', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('trial_days', 'smallinteger', ['signed' => false, 'null' => true])
            ->addColumn('duration_months', 'smallinteger', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['package_id', 'purchase_type'], ['unique' => true, 'name' => 'uniq_package_purchase_capabilities'])
            ->addIndex(['purchase_type'], ['name' => 'idx_package_purchase_capabilities_type'])
            ->addForeignKey(
                'package_id',
                'packages',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_package_purchase_capabilities_package'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('package_purchase_capabilities')->drop()->save();
    }
}
