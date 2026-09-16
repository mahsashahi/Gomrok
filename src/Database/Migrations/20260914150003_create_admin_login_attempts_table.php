<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `admin_login_attempts` — every login attempt, successful or not (CLAUDE.md:
 * "add login attempt logging"). `email` is stored even when it matches no
 * `admin_users` row (`admin_user_id` then `NULL`) so a login-enumeration
 * pattern against unknown addresses is still visible; `(email, created_at)`
 * is the index a lockout/rate-limit check reads.
 */
final class CreateAdminLoginAttemptsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('admin_login_attempts', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('email', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('admin_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => false])
            ->addColumn('succeeded', 'boolean', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['email', 'created_at'], ['name' => 'idx_admin_login_attempts_email_created'])
            ->addForeignKey('admin_user_id', 'admin_users', 'id', ['delete' => 'SET NULL', 'update' => 'CASCADE', 'constraint' => 'fk_admin_login_attempts_admin_user'])
            ->create();
    }

    public function down(): void
    {
        $this->table('admin_login_attempts')->drop()->save();
    }
}
