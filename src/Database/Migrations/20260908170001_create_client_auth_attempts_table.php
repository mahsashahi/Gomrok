<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `client_auth_attempts` — one append-only row per authentication attempt on
 * `/api/v1` (success and failure). Backs the admin security view and any future
 * rate limiter. `key_id` is the parsed token id only — never the secret.
 * (Phase 7 Q5.)
 */
final class CreateClientAuthAttemptsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('client_auth_attempts', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('outcome', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('reason', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('key_id', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('correlation_id', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['ip', 'created_at'], ['name' => 'idx_client_auth_attempts_ip_created'])
            ->addIndex(['key_id', 'created_at'], ['name' => 'idx_client_auth_attempts_key_created'])
            ->addIndex(['client_id', 'created_at'], ['name' => 'idx_client_auth_attempts_client_created'])
            ->addForeignKey(
                'client_id',
                'clients',
                'id',
                ['delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_client_auth_attempts_client_id'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('client_auth_attempts')->drop()->save();
    }
}
