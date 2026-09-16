<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `admin_sessions` — a DB-backed, hashed session token per admin login
 * (Phase 27 Q3). The browser holds the raw token in an `HttpOnly` cookie;
 * only `token_hash` (`sha256` of it, matching `ClientApiKey`'s existing
 * hash-and-compare pattern for a high-entropy random value) is ever stored.
 * `revoked_at` supports explicit logout and admin-initiated revocation from a
 * future session-management screen without deleting the audit trail.
 */
final class CreateAdminSessionsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('admin_sessions', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('admin_user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('last_used_at', 'datetime', ['null' => true])
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_admin_sessions_token_hash'])
            ->addIndex(['admin_user_id'], ['name' => 'idx_admin_sessions_admin_user'])
            ->addForeignKey('admin_user_id', 'admin_users', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_admin_sessions_admin_user'])
            ->create();
    }

    public function down(): void
    {
        $this->table('admin_sessions')->drop()->save();
    }
}
