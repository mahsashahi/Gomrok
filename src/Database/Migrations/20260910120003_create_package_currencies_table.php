<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `package_currencies` — the currencies a package is sold in (Phase 11 Q1).
 * Empty set = every currency (Q2 — fail open).
 */
final class CreatePackageCurrenciesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('package_currencies', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('currency_code', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['package_id', 'currency_code'], ['unique' => true, 'name' => 'uniq_package_currencies'])
            ->addIndex(['currency_code'], ['name' => 'idx_package_currencies_currency'])
            ->addForeignKey(
                'package_id',
                'packages',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_package_currencies_package'],
            )
            ->addForeignKey(
                'currency_code',
                'currencies',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_package_currencies_currency'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('package_currencies')->drop()->save();
    }
}
