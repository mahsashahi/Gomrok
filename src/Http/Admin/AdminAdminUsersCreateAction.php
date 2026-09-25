<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\CreateAdminUser\CreateAdminUserCommand;
use Gomrok\Modules\Admin\Application\CreateAdminUser\CreateAdminUserHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminModalReopen;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/admin-users` (Phase 27) — the "New admin user" modal's submit
 * target. The initial password is typed here by the creating admin, not
 * generated — nothing to keep out of the redirect URL.
 */
final readonly class AdminAdminUsersCreateAction
{
    use RedirectsToAdminUsers;

    public function __construct(
        private AdminContext $context,
        private CreateAdminUserHandler $handler,
        private AdminAdminUsersAction $screen,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::AdminUsersCreate)) {
            return AdminPermissionGuard::deny($response);
        }

        $body = AdminForm::body($request);
        $name = AdminForm::str($body, 'name');
        $email = AdminForm::str($body, 'email');
        $role = AdminForm::str($body, 'role', 'support_agent');
        // The typed password never round-trips into the reopened page's
        // source — it's cleared here, not merely omitted, so a validation
        // failure can't leak it into rendered HTML.
        $submittedValues = ['name' => $name, 'email' => $email, 'password' => '', 'role' => $role];

        $result = $this->handler->handle(new CreateAdminUserCommand(
            name: $name,
            email: $email,
            password: AdminForm::str($body, 'password'),
            role: $role,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->screen->reopen($request, $response, [], new AdminModalReopen('create-user', $submittedValues, $result->error()->message));
        }

        return $this->redirectToAdminUsers($response, success: 'Admin user created.');
    }
}
