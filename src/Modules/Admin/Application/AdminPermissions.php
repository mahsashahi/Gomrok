<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application;

use Gomrok\Modules\Admin\Domain\AdminRole;

/**
 * The code-defined role-to-permission matrix (Phase 27 DB design
 * confirmation — no `admin_roles`/`admin_permissions` tables; CLAUDE.md
 * requires only two fixed roles and no admin-editable permission UI is in
 * scope). `admin` holds every permission; `support_agent` holds every `.view`
 * permission and nothing else — CLAUDE.md: "can view clients, users,
 * packages, resolved prices, voucher status, payments, subscriptions,
 * webhook status, and notification status, but cannot modify provider
 * credentials, secrets, roles, permissions, pricing rules, voucher rules, or
 * system-level configuration."
 */
final class AdminPermissions
{
    /**
     * @return list<AdminPermission>
     */
    public static function for(AdminRole $role): array
    {
        return match ($role) {
            AdminRole::Admin => AdminPermission::cases(),
            AdminRole::SupportAgent => array_values(array_filter(
                AdminPermission::cases(),
                static fn (AdminPermission $p): bool => str_ends_with($p->value, '.view'),
            )),
        };
    }

    public static function roleHas(AdminRole $role, AdminPermission $permission): bool
    {
        return \in_array($permission, self::for($role), true);
    }

    private function __construct()
    {
    }
}
