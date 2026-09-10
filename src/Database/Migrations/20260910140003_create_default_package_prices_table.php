<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `default_package_prices` — the one baseline price per package (Phase 13 Q2).
 * `amount_minor` is in `currency_code`'s minor unit (`Shared\Domain\Money`).
 * Cross-currency pricing groups convert this via `client_exchange_rates`.
 */
final class CreateDefaultPackagePricesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('default_package_prices', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('amount_minor', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('currency_code', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['package_id'], ['unique' => true, 'name' => 'uniq_default_package_prices_package'])
            ->addForeignKey(
                'package_id',
                'packages',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_default_package_prices_package'],
            )
            ->addForeignKey(
                'currency_code',
                'currencies',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_default_package_prices_currency'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('default_package_prices')->drop()->save();
    }
}
