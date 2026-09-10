<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `provider_group_accounts` — the ordered list of provider accounts a
 * {@see provider_groups} row routes to (Phase 10 Q1). `priority` ascending = try
 * first; `is_enabled = 0` parks an account without losing its place. The app
 * enforces that the linked account's `client_id` matches the group's.
 */
final class CreateProviderGroupAccountsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('provider_group_accounts', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('provider_group_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('priority', 'smallinteger', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('is_enabled', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['provider_group_id', 'provider_account_id'], ['unique' => true, 'name' => 'uniq_provider_group_accounts'])
            ->addIndex(['provider_group_id', 'priority'], ['name' => 'idx_provider_group_accounts_priority'])
            ->addIndex(['provider_account_id'], ['name' => 'idx_provider_group_accounts_account'])
            ->addForeignKey(
                'provider_group_id',
                'provider_groups',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_group_accounts_group'],
            )
            ->addForeignKey(
                'provider_account_id',
                'provider_accounts',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_group_accounts_account'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_group_accounts')->drop()->save();
    }
}
