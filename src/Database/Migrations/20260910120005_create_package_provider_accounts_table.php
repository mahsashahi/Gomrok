<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `package_provider_accounts` — the provider accounts a package can be bought
 * through (Phase 11 Q1). Empty set = any of the client's accounts (Q2 — fail
 * open). App-enforced: the linked account belongs to the package's client.
 */
final class CreatePackageProviderAccountsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('package_provider_accounts', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['package_id', 'provider_account_id'], ['unique' => true, 'name' => 'uniq_package_provider_accounts'])
            ->addIndex(['provider_account_id'], ['name' => 'idx_package_provider_accounts_account'])
            ->addForeignKey(
                'package_id',
                'packages',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_package_provider_accounts_package'],
            )
            ->addForeignKey(
                'provider_account_id',
                'provider_accounts',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_package_provider_accounts_account'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('package_provider_accounts')->drop()->save();
    }
}
