<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `provider_account_countries` — which configured markets an account serves
 * (Phase 9 Q4). Used by the Phase 10 router to filter candidate accounts.
 */
final class CreateProviderAccountCountriesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('provider_account_countries', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('country_code', 'char', ['limit' => 2, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['provider_account_id', 'country_code'], ['unique' => true, 'name' => 'uniq_provider_account_countries'])
            ->addIndex(['country_code'], ['name' => 'idx_provider_account_countries_country'])
            ->addForeignKey(
                'provider_account_id',
                'provider_accounts',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_account_countries_account_id'],
            )
            ->addForeignKey(
                'country_code',
                'countries',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_provider_account_countries_country'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_account_countries')->drop()->save();
    }
}
