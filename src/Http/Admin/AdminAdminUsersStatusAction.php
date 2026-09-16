<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\SetAdminUserStatus\SetAdminUserStatusCommand;
use Gomrok\Modules\Admin\Application\SetAdminUserStatus\SetAdminUserStatusHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/admin-users/{adminUserId}/status` (Phase 27). Moving to
 * `active` requires `admin_users.update`; moving to `disabled` or `locked`
 * requires `admin_users.disable` — mirroring how the Vouchers screen splits
 * its own dedicated `.disable` permission from `.update`. The handler itself
 * refuses to let an admin leave their own account non-active, regardless of
 * permission.
 */
final readonly class AdminAdminUsersStatusAction
{
    use RedirectsToAdminUsers;

    public function __construct(
        private AdminContext $context,
        private SetAdminUserStatusHandler $handler,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = AdminForm::body($request);
        $status = AdminForm::str($body, 'status', 'active');

        $required = $status === 'active' ? AdminPermission::AdminUsersUpdate : AdminPermission::AdminUsersDisable;
        if (!AdminPermissionGuard::allows($this->context, $required)) {
            return AdminPermissionGuard::deny($response);
        }

        $adminUserId = (int) $args['adminUserId'];

        $result = $this->handler->handle(new SetAdminUserStatusCommand(
            adminUserId: $adminUserId,
            status: $status,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->redirectToAdminUsers($response, error: $result->error()->message);
        }

        return $this->redirectToAdminUsers($response, success: 'Admin user status updated.');
    }
}
