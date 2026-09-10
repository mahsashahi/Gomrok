<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `currencies` — ISO 4217 reference data. Static; seeded from brick/money's
 * ISOCurrencyProvider. No timestamps.
 */
final class CreateCurrenciesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('currencies', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('code', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('numeric_code', 'smallinteger', ['signed' => false, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('minor_unit_scale', 'tinyinteger', ['signed' => false, 'null' => false])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uniq_currencies_code'])
            ->addIndex(['numeric_code'], ['unique' => true, 'name' => 'uniq_currencies_numeric_code'])
            ->create();
    }

    public function down(): void
    {
        $this->table('currencies')->drop()->save();
    }
}
