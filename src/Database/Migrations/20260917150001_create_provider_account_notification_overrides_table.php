<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `provider_account_notification_overrides` — Phase 28 Q1 (user-specified hybrid):
 * `client_endpoints` (Phase 6) stays the client-scoped default callback URL per
 * purpose; a provider account may additionally register its own override URL for
 * a purpose, used only when that specific account produced the notified event.
 *
 * Deliberately separate from `provider_account_endpoints` (Phase 9), which is
 * exclusively inbound (webhook/callback/return verification config) — this table
 * is outbound only and shares no rows or concerns with it.
 */
final class CreateProviderAccountNotificationOverridesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('provider_account_notification_overrides', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('purpose', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('url', 'string', ['limit' => 2048, 'null' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['provider_account_id', 'purpose'], ['unique' => true, 'name' => 'uniq_pano_account_purpose'])
            ->addForeignKey(
                'provider_account_id',
                'provider_accounts',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_pano_provider_account_id'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_account_notification_overrides')->drop()->save();
    }
}
