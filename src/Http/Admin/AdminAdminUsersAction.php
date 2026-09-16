<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermissions;
use Gomrok\Modules\Admin\Application\AdminUsers\AdminUsersScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/admin-users` (Phase 27) — a flat table of every admin panel
 * user, matching {@see AdminClientsAction}'s "not scoped to the active
 * client" shape (admin accounts are global, not per-client).
 */
final readonly class AdminAdminUsersAction
{
    public function __construct(
        private AdminContext $context,
        private AdminUsersScreenHandler $screen,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $error = \is_string($query['error'] ?? null) ? $query['error'] : null;
        $success = \is_string($query['success'] ?? null) ? $query['success'] : null;
        $role = AdminRole::from($this->context->admin()->role);

        return $this->view->render($response, 'admin-users.html.twig', [
            'admin' => $this->context->admin(),
            'active_nav' => 'admin_users',
            'clients' => [],
            'active_client' => null,
            'screen' => $this->screen->build($this->context->admin()->id),
            'error' => $error,
            'success' => $success,
            'permissions' => array_map(static fn ($p) => $p->value, AdminPermissions::for($role)),
        ]);
    }
}
