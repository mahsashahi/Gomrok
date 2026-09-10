<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * Adds the `client_id` foreign keys deferred from Phase 5 — the cross-cutting
 * tables were created before `clients` existed. `idempotency_keys.client_id` is
 * NOT NULL and its rows are ephemeral → CASCADE. `audit_logs` / `error_logs`
 * keep their history if a client is ever deleted → SET NULL.
 */
final class AddClientFksToCrossCuttingTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('idempotency_keys')
            ->addForeignKey(
                'client_id',
                'clients',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_idempotency_keys_client_id'],
            )
            ->update();

        $this->table('audit_logs')
            ->addForeignKey(
                'client_id',
                'clients',
                'id',
                ['delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_audit_logs_client_id'],
            )
            ->update();

        $this->table('error_logs')
            ->addForeignKey(
                'client_id',
                'clients',
                'id',
                ['delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_error_logs_client_id'],
            )
            ->update();
    }

    public function down(): void
    {
        $this->table('error_logs')->dropForeignKey('client_id', 'fk_error_logs_client_id')->update();
        $this->table('audit_logs')->dropForeignKey('client_id', 'fk_audit_logs_client_id')->update();
        $this->table('idempotency_keys')->dropForeignKey('client_id', 'fk_idempotency_keys_client_id')->update();
    }
}
