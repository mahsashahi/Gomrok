<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `provider_type_capabilities` — which capabilities each provider type declares
 * in principle (Phase 8 Q3). Seeded for stripe + paypal (Q5); ziraat/mollie rows
 * land in their adapter phases. Static config, no timestamps.
 */
final class CreateProviderTypeCapabilitiesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('provider_type_capabilities', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('provider_type_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('capability_id', 'integer', ['signed' => false, 'null' => false])
            ->addIndex(
                ['provider_type_id', 'capability_id'],
                ['unique' => true, 'name' => 'uniq_provider_type_capabilities'],
            )
            ->addIndex(['capability_id'], ['name' => 'idx_provider_type_capabilities_capability'])
            ->addForeignKey(
                'provider_type_id',
                'provider_types',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_type_capabilities_type'],
            )
            ->addForeignKey(
                'capability_id',
                'provider_capabilities',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_type_capabilities_capability'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_type_capabilities')->drop()->save();
    }
}
