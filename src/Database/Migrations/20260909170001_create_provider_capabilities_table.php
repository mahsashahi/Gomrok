<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `provider_capabilities` — the catalogue of capability flags. Seeded mirror of
 * the `Gomrok\Modules\Providers\Domain\Capability` enum (the source of truth —
 * Phase 8 Q1). Static reference data, no timestamps.
 */
final class CreateProviderCapabilitiesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('provider_capabilities', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('code', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('capability_group', 'string', ['limit' => 20, 'null' => false])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uniq_provider_capabilities_code'])
            ->addIndex(['capability_group'], ['name' => 'idx_provider_capabilities_group'])
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_capabilities')->drop()->save();
    }
}
