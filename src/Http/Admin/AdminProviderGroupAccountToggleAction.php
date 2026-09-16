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
 * `POST /admin/providers/groups/{groupId}/accounts/{accountId}/toggle`
 * (Phase 27) — flips a provider account's `isEnabled` link within one
 * routing group, without losing its place in the priority chain.
 */
final readonly class AdminProviderGroupAccountToggleAction
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

        $result = $this->handler->toggle($groupId, $accountId, $this->context->admin()->id);
        if ($result->isErr()) {
            return $this->redirectToProviders($response, ['tab' => 'groups', 'group' => $slug], error: $result->error()->message);
        }

        return $this->redirectToProviders($response, ['tab' => 'groups', 'group' => $slug], success: 'Link updated.');
    }
}
