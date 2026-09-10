<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `packages` — the client-owned catalogue entry (Phase 11). Per the Package
 * Ownership Rule: one table carrying `client_id`, `code` unique **per client**,
 * no global catalogue and no `client_packages` junction. `status` (`active` /
 * `disabled`) is the per-client hide switch. Richer metadata (trial, duration,
 * badge, purchase capabilities) lands in Phase 12; `metadata` JSON is the
 * free-form escape hatch until then.
 */
final class CreatePackagesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('packages', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('code', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'active'])
            ->addColumn('metadata', 'json', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['client_id', 'code'], ['unique' => true, 'name' => 'uniq_packages_client_code'])
            ->addIndex(['client_id', 'status'], ['name' => 'idx_packages_client_status'])
            ->addForeignKey(
                'client_id',
                'clients',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_packages_client_id'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('packages')->drop()->save();
    }
}
