<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\ResetAdminUserPassword\ResetAdminUserPasswordCommand;
use Gomrok\Modules\Admin\Application\ResetAdminUserPassword\ResetAdminUserPasswordHandler;
use Gomrok\Modules\Admin\Domain\AdminUserRepository;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminModalReopen;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/admin-users/{adminUserId}/reset-password` (Phase 27) — the
 * new password is typed by the acting admin in the modal and submitted
 * directly; there is no generated secret to protect from a URL.
 */
final readonly class AdminAdminUserPasswordResetAction
{
    use RedirectsToAdminUsers;

    public function __construct(
        private AdminContext $context,
        private ResetAdminUserPasswordHandler $handler,
        private AdminUserRepository $adminUsers,
        private AdminAdminUsersAction $screen,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::AdminUsersUpdate)) {
            return AdminPermissionGuard::deny($response);
        }

        $adminUserId = (int) $args['adminUserId'];
        $body = AdminForm::body($request);

        $result = $this->handler->handle(new ResetAdminUserPasswordCommand(
            adminUserId: $adminUserId,
            newPassword: AdminForm::str($body, 'new_password'),
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            $user = $this->adminUsers->findById($adminUserId);
            $name = $user !== null ? $user->name() : '';
            // The typed new password is never echoed back — reopened with
            // an empty password field, same reasoning as
            // AdminAdminUsersCreateAction.
            $submittedValues = ['id' => $adminUserId, 'name' => $name, 'new_password' => ''];

            return $this->screen->reopen($request, $response, [], new AdminModalReopen('reset-password', $submittedValues, $result->error()->message));
        }

        return $this->redirectToAdminUsers($response, success: 'Password reset.');
    }
}
