<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `provider_group_countries` — the markets a non-default {@see provider_groups}
 * row covers (Phase 10 Q1). The default group has zero rows here. The app
 * enforces that a country sits in at most one non-default group per
 * `(client_id, device_type)`.
 */
final class CreateProviderGroupCountriesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('provider_group_countries', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('provider_group_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('country_code', 'char', ['limit' => 2, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['provider_group_id', 'country_code'], ['unique' => true, 'name' => 'uniq_provider_group_countries'])
            ->addIndex(['country_code'], ['name' => 'idx_provider_group_countries_country'])
            ->addForeignKey(
                'provider_group_id',
                'provider_groups',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_group_countries_group'],
            )
            ->addForeignKey(
                'country_code',
                'countries',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_provider_group_countries_country'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_group_countries')->drop()->save();
    }
}
