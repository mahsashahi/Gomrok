<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `provider_accounts` — a client's credentials for one provider type in one
 * `mode` (live / test — Phase 9 Q2). The secret key is stored `SecretCipher`-
 * encrypted (Q1); `public_key` is not secret. Soft-disable like `clients`.
 */
final class CreateProviderAccountsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('provider_accounts', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('provider_type_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('slug', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('mode', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'active'])
            ->addColumn('public_key', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('secret_ciphertext', 'text', ['null' => false])
            ->addColumn('secret_last_four', 'char', ['limit' => 4, 'null' => false])
            ->addColumn('disabled_at', 'datetime', ['null' => true])
            ->addColumn('disabled_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('disabled_reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['client_id', 'slug'], ['unique' => true, 'name' => 'uniq_provider_accounts_client_slug'])
            ->addIndex(['client_id', 'provider_type_id', 'mode'], ['name' => 'idx_provider_accounts_client_type_mode'])
            ->addIndex(['provider_type_id'], ['name' => 'idx_provider_accounts_provider_type'])
            ->addIndex(['status'], ['name' => 'idx_provider_accounts_status'])
            ->addForeignKey(
                'client_id',
                'clients',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_accounts_client_id'],
            )
            ->addForeignKey(
                'provider_type_id',
                'provider_types',
                'id',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_provider_accounts_provider_type_id'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_accounts')->drop()->save();
    }
}
