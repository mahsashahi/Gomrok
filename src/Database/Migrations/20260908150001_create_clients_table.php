<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `clients` — the tenant record. Every business row in Gomrok belongs to one
 * client. `slug` is the immutable public handle; `id` is the FK everywhere.
 * `status` is a soft, reversible `active` / `disabled` (Phase 6 Q4).
 * `notification_signing_secret` HMAC-signs Gomrok → client callbacks and is
 * never logged.
 */
final class CreateClientsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('clients', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('slug', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'active'])
            ->addColumn('default_currency', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('default_country', 'char', ['limit' => 2, 'null' => true])
            ->addColumn('timezone', 'string', ['limit' => 64, 'null' => false, 'default' => 'UTC'])
            ->addColumn('notification_signing_secret', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('disabled_at', 'datetime', ['null' => true])
            ->addColumn('disabled_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('disabled_reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['slug'], ['unique' => true, 'name' => 'uniq_clients_slug'])
            ->addIndex(['status'], ['name' => 'idx_clients_status'])
            ->addIndex(['default_currency'], ['name' => 'idx_clients_default_currency'])
            ->addIndex(['default_country'], ['name' => 'idx_clients_default_country'])
            ->addForeignKey(
                'default_currency',
                'currencies',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_clients_default_currency'],
            )
            ->addForeignKey(
                'default_country',
                'countries',
                'code',
                ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_clients_default_country'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('clients')->drop()->save();
    }
}
