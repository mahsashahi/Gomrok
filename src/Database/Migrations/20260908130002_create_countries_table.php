<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `countries` — the markets Gomrok operates in (ISO 3166-1 alpha-2). Curated,
 * not the full ISO list; new markets are added by a later migration/seed.
 * `default_currency` FKs to `currencies.code`. No timestamps.
 */
final class CreateCountriesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('countries', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('code', 'char', ['limit' => 2, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('default_currency', 'char', ['limit' => 3, 'null' => false])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uniq_countries_code'])
            ->addIndex(['default_currency'], ['name' => 'idx_countries_default_currency'])
            ->addForeignKey(
                'default_currency',
                'currencies',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_countries_default_currency'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('countries')->drop()->save();
    }
}
