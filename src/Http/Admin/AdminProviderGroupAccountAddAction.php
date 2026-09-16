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
 * `POST /admin/providers/groups/{groupId}/accounts` (Phase 27) — appends a
 * provider account to the end of a routing group's priority chain.
 */
final readonly class AdminProviderGroupAccountAddAction
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
        $body = AdminForm::body($request);
        $slug = AdminForm::nullableStr($body, 'group');
        $providerAccountId = AdminForm::nullableInt($body, 'provider_account_id');

        if ($providerAccountId === null) {
            return $this->redirectToProviders($response, ['tab' => 'groups', 'group' => $slug], error: 'Choose a provider account.');
        }

        $result = $this->handler->add($groupId, $providerAccountId, $this->context->admin()->id);
        if ($result->isErr()) {
            return $this->redirectToProviders($response, ['tab' => 'groups', 'group' => $slug], error: $result->error()->message);
        }

        return $this->redirectToProviders($response, ['tab' => 'groups', 'group' => $slug], success: 'Account added to group.');
    }
}
