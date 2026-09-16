<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\AdminPermissions;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Psr\Http\Message\ResponseInterface;

/**
 * Backend-layer enforcement of {@see AdminPermissions} for the Packaging &amp;
 * Pricing write endpoints (Phase 27 Increment B) — CLAUDE.md: "Hiding a
 * button in the UI is not enough. Every admin action must be checked on the
 * backend." Every mutation action calls {@see self::deny()} before touching
 * any handler; a `support_agent` (who holds only `.view` permissions) gets a
 * plain 403, regardless of what the UI happened to render.
 */
final class AdminPermissionGuard
{
    public static function allows(AdminContext $context, AdminPermission $permission): bool
    {
        $role = AdminRole::from($context->admin()->role);

        return AdminPermissions::roleHas($role, $permission);
    }

    public static function deny(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write('Forbidden — your admin role does not have this permission.');

        return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    private function __construct()
    {
    }
}
