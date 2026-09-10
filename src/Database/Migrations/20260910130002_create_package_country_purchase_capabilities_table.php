<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `package_country_purchase_capabilities` — per-country override of a package's
 * purchase-type set (Phase 12 Q2). When rows exist for `(package, country)`
 * they **replace** the global set for that country; absent ⇒ inherit
 * `package_purchase_capabilities`. A listed type must be in the package's global
 * set (validated in the handler).
 */
final class CreatePackageCountryPurchaseCapabilitiesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('package_country_purchase_capabilities', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('country_code', 'char', ['limit' => 2, 'null' => false])
            ->addColumn('purchase_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(
                ['package_id', 'country_code', 'purchase_type'],
                ['unique' => true, 'name' => 'uniq_package_country_purchase_capabilities'],
            )
            ->addIndex(['country_code'], ['name' => 'idx_package_country_purchase_capabilities_country'])
            ->addForeignKey(
                'package_id',
                'packages',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_package_country_purchase_caps_package'],
            )
            ->addForeignKey(
                'country_code',
                'countries',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_package_country_purchase_caps_country'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('package_country_purchase_capabilities')->drop()->save();
    }
}
