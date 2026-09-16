<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\Packaging\ReorderPricingGroupPackages\ReorderPricingGroupPackagesCommand;
use Gomrok\Modules\Admin\Application\Packaging\ReorderPricingGroupPackages\ReorderPricingGroupPackagesHandler;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/packaging/groups/{groupId}/reorder` (Phase 27 Increment B) —
 * the drag-to-reorder target. The dragged order is built client-side
 * (`packaging.js`) into a hidden `order` input as a comma-separated list of
 * package ids, then submitted as a normal form post.
 */
final readonly class AdminGroupReorderAction
{
    use RedirectsToPackaging;

    public function __construct(
        private AdminContext $context,
        private PricingGroupRepository $groups,
        private ReorderPricingGroupPackagesHandler $handler,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::PricingUpdate)) {
            return AdminPermissionGuard::deny($response);
        }

        $groupId = (int) $args['groupId'];
        $group = $this->groups->findById($groupId);
        if ($group === null) {
            return $this->redirectToPackaging($response, ['tab' => 'groups'], error: 'Pricing group not found.');
        }
        $slug = $group->slug()->value;

        $body = AdminForm::body($request);
        $order = AdminForm::str($body, 'order');

        $packageIds = array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', $order),
        ), static fn (int $id): bool => $id > 0));

        if ($packageIds === []) {
            return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $slug], error: 'Nothing to reorder.');
        }

        $result = $this->handler->handle(new ReorderPricingGroupPackagesCommand(
            pricingGroupId: $groupId,
            packageIdsInOrder: $packageIds,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $slug], error: $result->error()->message);
        }

        return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $slug], success: 'Order updated.');
    }
}
