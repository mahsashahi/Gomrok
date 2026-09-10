<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `provider_group_purchase_types` — the purchase types a {@see provider_groups}
 * row sells (Phase 10 Q2). `purchase_type` holds a
 * `Gomrok\Modules\Providers\Domain\PurchaseType` value (app-enforced). An empty
 * set = nothing sellable (fail closed) — the router intersects this with each
 * account's provider-type declaration and rejects, never downgrades, an
 * unsupported request.
 */
final class CreateProviderGroupPurchaseTypesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('provider_group_purchase_types', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('provider_group_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('purchase_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['provider_group_id', 'purchase_type'], ['unique' => true, 'name' => 'uniq_provider_group_purchase_types'])
            ->addIndex(['purchase_type'], ['name' => 'idx_provider_group_purchase_types_type'])
            ->addForeignKey(
                'provider_group_id',
                'provider_groups',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_group_purchase_types_group'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_group_purchase_types')->drop()->save();
    }
}
