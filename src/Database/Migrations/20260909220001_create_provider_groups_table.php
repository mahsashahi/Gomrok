<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `provider_groups` — the unit of country → provider routing config (Phase 10
 * Q1). A group binds a set of countries (child table) to an ordered list of the
 * client's provider accounts, plus the purchase types / methods that market
 * sells. `is_default = 1` is the client's fallback group (no country rows); the
 * app enforces one default per `(client_id, device_type)`. `currency_code` NULL
 * = don't gate on currency (pricing groups own currency from Phase 13).
 * `status` is a soft, reversible `active` / `disabled`.
 */
final class CreateProviderGroupsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('provider_groups', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('slug', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('is_default', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('device_type', 'string', ['limit' => 10, 'null' => true])
            ->addColumn('currency_code', 'char', ['limit' => 3, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'active'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['client_id', 'slug'], ['unique' => true, 'name' => 'uniq_provider_groups_client_slug'])
            ->addIndex(['client_id', 'is_default', 'device_type'], ['name' => 'idx_provider_groups_client_default'])
            ->addIndex(['client_id', 'status'], ['name' => 'idx_provider_groups_client_status'])
            ->addForeignKey(
                'client_id',
                'clients',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_groups_client_id'],
            )
            ->addForeignKey(
                'currency_code',
                'currencies',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_provider_groups_currency'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_groups')->drop()->save();
    }
}
