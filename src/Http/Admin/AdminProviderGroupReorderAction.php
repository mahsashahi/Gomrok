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
 * `POST /admin/providers/groups/{groupId}/reorder` (Phase 27) — the
 * drag-to-reorder target for a routing group's account priority chain. Same
 * client-side pattern as {@see AdminGroupReorderAction} (Packaging): the
 * dragged order is built into a hidden `order` input as a comma-separated
 * list of account ids, then submitted as a normal form post.
 */
final readonly class AdminProviderGroupReorderAction
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
        $order = AdminForm::str($body, 'order');

        $accountIds = array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', $order),
        ), static fn (int $id): bool => $id > 0));

        if ($accountIds === []) {
            return $this->redirectToProviders($response, ['tab' => 'groups', 'group' => $slug], error: 'Nothing to reorder.');
        }

        $result = $this->handler->reorder($groupId, $accountIds, $this->context->admin()->id);
        if ($result->isErr()) {
            return $this->redirectToProviders($response, ['tab' => 'groups', 'group' => $slug], error: $result->error()->message);
        }

        return $this->redirectToProviders($response, ['tab' => 'groups', 'group' => $slug], success: 'Order updated.');
    }
}
