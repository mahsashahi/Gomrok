<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `package_provider_definitions` — where a package exists on one provider
 * account's side (Phase 12 Q3). One row per linked `(package, provider
 * account)`, created lazily. `sync_state` is a
 * `Gomrok\Modules\Packages\Domain\PackageProviderSyncState` value
 * (`not_created` / `synced` / `drift` / `not_needed`). `remote_id` is indexed
 * for reverse lookup from a provider webhook (Phase 25). App-enforced: the
 * provider account belongs to the package's client.
 */
final class CreatePackageProviderDefinitionsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('package_provider_definitions', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('provider_side_name', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('remote_id', 'string', ['limit' => 191, 'null' => true])
            ->addColumn('sync_state', 'string', ['limit' => 20, 'null' => false, 'default' => 'not_created'])
            ->addColumn('last_synced_at', 'datetime', ['null' => true])
            ->addColumn('last_error', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['package_id', 'provider_account_id'], ['unique' => true, 'name' => 'uniq_package_provider_definitions'])
            ->addIndex(['provider_account_id'], ['name' => 'idx_package_provider_definitions_account'])
            ->addIndex(['sync_state'], ['name' => 'idx_package_provider_definitions_state'])
            ->addIndex(['remote_id'], ['name' => 'idx_package_provider_definitions_remote'])
            ->addForeignKey(
                'package_id',
                'packages',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_package_provider_definitions_package'],
            )
            ->addForeignKey(
                'provider_account_id',
                'provider_accounts',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_package_provider_definitions_account'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('package_provider_definitions')->drop()->save();
    }
}
