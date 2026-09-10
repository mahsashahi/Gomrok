<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `client_api_keys` — one row per issued key (Phase 6 Q1). The token is shown
 * once as `gk_live_<key_id>.<secret>`; only `key_id` (public lookup) and
 * `secret_hash = sha256(secret)` are stored. `prefix` + `last_four` are for
 * display. Revocation is a status flip — rows are never deleted.
 */
final class CreateClientApiKeysTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('client_api_keys', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('key_id', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('secret_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('prefix', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('last_four', 'char', ['limit' => 4, 'null' => false])
            ->addColumn('label', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'active'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('last_used_at', 'datetime', ['null' => true])
            ->addColumn('expires_at', 'datetime', ['null' => true])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('revoked_by', 'integer', ['signed' => false, 'null' => true])
            ->addIndex(['key_id'], ['unique' => true, 'name' => 'uniq_client_api_keys_key_id'])
            ->addIndex(['client_id'], ['name' => 'idx_client_api_keys_client'])
            ->addIndex(['client_id', 'status'], ['name' => 'idx_client_api_keys_client_status'])
            ->addForeignKey(
                'client_id',
                'clients',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_client_api_keys_client_id'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('client_api_keys')->drop()->save();
    }
}
