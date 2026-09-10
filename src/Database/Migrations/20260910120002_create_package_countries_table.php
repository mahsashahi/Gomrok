<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `package_countries` — the markets a package is sold in (Phase 11 Q1). An
 * **empty** set means "available in every country" (Q2 — fail open); rows
 * restrict.
 */
final class CreatePackageCountriesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('package_countries', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('country_code', 'char', ['limit' => 2, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['package_id', 'country_code'], ['unique' => true, 'name' => 'uniq_package_countries'])
            ->addIndex(['country_code'], ['name' => 'idx_package_countries_country'])
            ->addForeignKey(
                'package_id',
                'packages',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_package_countries_package'],
            )
            ->addForeignKey(
                'country_code',
                'countries',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_package_countries_country'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('package_countries')->drop()->save();
    }
}
