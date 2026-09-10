<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `client_endpoints` — the callback URLs Gomrok posts status updates to
 * (Phase 6 Q2). One active URL per `(client_id, purpose)` for now.
 */
final class CreateClientEndpointsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('client_endpoints', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('purpose', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('url', 'string', ['limit' => 2048, 'null' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['client_id', 'purpose'], ['unique' => true, 'name' => 'uniq_client_endpoints_client_purpose'])
            ->addIndex(['client_id'], ['name' => 'idx_client_endpoints_client'])
            ->addForeignKey(
                'client_id',
                'clients',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_client_endpoints_client_id'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('client_endpoints')->drop()->save();
    }
}
