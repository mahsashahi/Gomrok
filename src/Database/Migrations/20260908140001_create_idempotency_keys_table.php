<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `idempotency_keys` — shields client write endpoints against duplicate
 * submission. A row is claimed (`status = processing`) before the handler runs,
 * moved to `done` (with the created entity's `target_type`/`target_id`) on
 * success, or `failed` on error. `expires_at` = `created_at + 24h`; an expired
 * row is treated as absent on lookup and deleted by a purge job.
 *
 * `client_id` carries no FK yet — `clients` lands in Phase 6, which adds the
 * `fk_idempotency_keys_client_id` constraint via an additive migration.
 */
final class CreateIdempotencyKeysTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('idempotency_keys', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('idempotency_key', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('request_fingerprint', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('target_type', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('target_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('response_status', 'smallinteger', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addIndex(
                ['client_id', 'idempotency_key'],
                ['unique' => true, 'name' => 'uniq_idempotency_keys_client_key'],
            )
            ->addIndex(['expires_at'], ['name' => 'idx_idempotency_keys_expires_at'])
            ->create();
    }

    public function down(): void
    {
        $this->table('idempotency_keys')->drop()->save();
    }
}
