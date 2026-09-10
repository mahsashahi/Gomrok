<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `pricing_group_countries` — the markets a non-default {@see pricing_groups}
 * row covers (Phase 13 Q1). Overlap **is** allowed: a country in several groups
 * is resolved by `priority`. The default group has no rows here.
 */
final class CreatePricingGroupCountriesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pricing_group_countries', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('pricing_group_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('country_code', 'char', ['limit' => 2, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['pricing_group_id', 'country_code'], ['unique' => true, 'name' => 'uniq_pricing_group_countries'])
            ->addIndex(['country_code'], ['name' => 'idx_pricing_group_countries_country'])
            ->addForeignKey(
                'pricing_group_id',
                'pricing_groups',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_pricing_group_countries_group'],
            )
            ->addForeignKey(
                'country_code',
                'countries',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_pricing_group_countries_country'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('pricing_group_countries')->drop()->save();
    }
}
