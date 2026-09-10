<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `pricing_groups` — a client's country grouping for pricing (Phase 13 Q1).
 * Priority-ordered; a country may be in several groups and the lowest `priority`
 * wins (unlike the exclusive Phase 10 provider groups). `is_default` is the
 * fallback, has no country rows, and is forced last at resolve time regardless
 * of stored priority. One currency per group.
 */
final class CreatePricingGroupsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pricing_groups', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('slug', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('priority', 'smallinteger', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('device_type', 'string', ['limit' => 10, 'null' => true])
            ->addColumn('currency_code', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('is_default', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'active'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['client_id', 'slug'], ['unique' => true, 'name' => 'uniq_pricing_groups_client_slug'])
            ->addIndex(['client_id', 'status', 'priority'], ['name' => 'idx_pricing_groups_client_priority'])
            ->addForeignKey(
                'client_id',
                'clients',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_pricing_groups_client_id'],
            )
            ->addForeignKey(
                'currency_code',
                'currencies',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_pricing_groups_currency'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('pricing_groups')->drop()->save();
    }
}
