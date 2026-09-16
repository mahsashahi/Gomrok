<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\Providers\ManageProviderGroupAccountsForAdmin\ManageProviderGroupAccountsForAdminHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/providers/groups/{groupId}/accounts/{accountId}/remove`
 * (Phase 27) — drops a provider account from a routing group's priority
 * chain entirely (not the same as disabling its link — see the toggle
 * action).
 */
final readonly class AdminProviderGroupAccountRemoveAction
{
    use RedirectsToProviders;

    public function __construct(
        private AdminContext $context,
        private ManageProviderGroupAccountsForAdminHandler $handler,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::ProviderConfigsUpdate)) {
            return AdminPermissionGuard::deny($response);
        }

        $groupId = (int) $args['groupId'];
        $accountId = (int) $args['accountId'];
        $body = AdminForm::body($request);
        $slug = AdminForm::nullableStr($body, 'group');

        $result = $this->handler->remove($groupId, $accountId, $this->context->admin()->id);
        if ($result->isErr()) {
            return $this->redirectToProviders($response, ['tab' => 'groups', 'group' => $slug], error: $result->error()->message);
        }

        return $this->redirectToProviders($response, ['tab' => 'groups', 'group' => $slug], success: 'Account removed from group.');
    }
}
