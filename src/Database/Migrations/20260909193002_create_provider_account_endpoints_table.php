<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `provider_account_endpoints` — per-account verification config for inbound
 * provider messages (Phase 9 Q3). `kind` = webhook | callback | return.
 * `token` is the opaque URL segment in `/api/v1/webhooks/{provider}/{token}`
 * (consumed by the Webhooks module, Phase 25); `signing_secret_ciphertext`
 * is `SecretCipher`-encrypted. Both nullable for `return`-kind rows.
 */
final class CreateProviderAccountEndpointsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('provider_account_endpoints', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('kind', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('token', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('signing_secret_ciphertext', 'text', ['null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['token'], ['unique' => true, 'name' => 'uniq_provider_account_endpoints_token'])
            ->addIndex(['provider_account_id', 'kind'], ['name' => 'idx_provider_account_endpoints_account_kind'])
            ->addForeignKey(
                'provider_account_id',
                'provider_accounts',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_account_endpoints_account_id'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_account_endpoints')->drop()->save();
    }
}
