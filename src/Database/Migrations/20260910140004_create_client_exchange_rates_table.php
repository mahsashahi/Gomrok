<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `client_exchange_rates` — client-configured, effective-dated FX rates (Phase
 * 13 Q2). Used only when a `status = default` pricing-group package resolves in
 * a currency different from its `default_package_prices` currency. The most
 * recent row with `effective_from <= now` for the `(base, quote)` pair wins.
 * `rate` = "1 base_currency = rate quote_currency".
 */
final class CreateClientExchangeRatesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('client_exchange_rates', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('base_currency', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('quote_currency', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('rate', 'decimal', ['precision' => 18, 'scale' => 8, 'null' => false])
            ->addColumn('effective_from', 'datetime', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(
                ['client_id', 'base_currency', 'quote_currency', 'effective_from'],
                ['unique' => true, 'name' => 'uniq_client_exchange_rates'],
            )
            ->addForeignKey(
                'client_id',
                'clients',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_client_exchange_rates_client'],
            )
            ->addForeignKey(
                'base_currency',
                'currencies',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_client_exchange_rates_base'],
            )
            ->addForeignKey(
                'quote_currency',
                'currencies',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_client_exchange_rates_quote'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('client_exchange_rates')->drop()->save();
    }
}
