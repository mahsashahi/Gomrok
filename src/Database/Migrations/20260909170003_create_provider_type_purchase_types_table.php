<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `provider_type_purchase_types` — which purchase types each provider type
 * supports (Phase 8 Q2/Q3). `purchase_type` holds a
 * `Gomrok\Modules\Providers\Domain\PurchaseType` value (app-enforced). Seeded
 * for stripe + paypal. Static config, no timestamps.
 */
final class CreateProviderTypePurchaseTypesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('provider_type_purchase_types', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('provider_type_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('purchase_type', 'string', ['limit' => 20, 'null' => false])
            ->addIndex(
                ['provider_type_id', 'purchase_type'],
                ['unique' => true, 'name' => 'uniq_provider_type_purchase_types'],
            )
            ->addIndex(['purchase_type'], ['name' => 'idx_provider_type_purchase_types_type'])
            ->addForeignKey(
                'provider_type_id',
                'provider_types',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_type_purchase_types_type'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_type_purchase_types')->drop()->save();
    }
}
