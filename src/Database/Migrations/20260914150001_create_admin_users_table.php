<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `admin_users` — the people who operate the admin panel (Phase 27). `role`
 * is a fixed two-value enum (`admin` / `support_agent`, CLAUDE.md: "only these
 * two roles are required for now") stored directly on the row rather than via
 * `admin_roles`/`admin_permissions` join tables — the permission-key-to-role
 * matrix is code-defined (`Gomrok\Modules\Admin\Application\AdminPermissions`),
 * confirmed in `PhaseResults/PhaseDecisions.md` (Phase 27 DB design
 * confirmation). `password_hash` uses PHP's `password_hash()` (bcrypt/argon2i)
 * — a slow hash, deliberately different from `ClientApiKey`'s fast `sha256`,
 * because a password is low-entropy and user-chosen while an API secret is
 * high-entropy and random.
 */
final class CreateAdminUsersTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('admin_users', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('name', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('email', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('role', 'enum', ['values' => ['admin', 'support_agent'], 'null' => false])
            ->addColumn('status', 'enum', ['values' => ['active', 'disabled', 'locked'], 'null' => false, 'default' => 'active'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['email'], ['unique' => true, 'name' => 'uq_admin_users_email'])
            ->create();
    }

    public function down(): void
    {
        $this->table('admin_users')->drop()->save();
    }
}
