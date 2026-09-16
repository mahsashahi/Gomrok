<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Pricing\Application\CreatePriceList\CreatePriceListCommand;
use Gomrok\Modules\Pricing\Application\CreatePriceList\CreatePriceListHandler;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/packaging/price-lists` (Phase 27 Increment B) — the "Create
 * A/B price list" modal's submit target. `pricing_group_id` is a hidden
 * field carried by the modal (the group whose "Pricing groups" detail it was
 * opened from).
 */
final readonly class AdminPriceListsCreateAction
{
    use RedirectsToPackaging;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private PricingGroupRepository $groups,
        private CreatePriceListHandler $handler,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::PricingCreate)) {
            return AdminPermissionGuard::deny($response);
        }

        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);
        if ($activeClient === null) {
            return $this->redirectToPackaging($response, ['tab' => 'groups'], error: 'No active client.');
        }

        $body = AdminForm::body($request);
        $groupId = AdminForm::nullableInt($body, 'pricing_group_id');
        $name = AdminForm::str($body, 'name');
        $factor = AdminForm::str($body, 'factor', '1.0000');

        if ($groupId === null) {
            return $this->redirectToPackaging($response, ['tab' => 'groups'], error: 'Choose a pricing group.');
        }

        $group = $this->groups->findById($groupId);
        $slug = $group?->slug()->value;

        $result = $this->handler->handle(new CreatePriceListCommand(
            clientId: $activeClient->id,
            pricingGroupId: $groupId,
            name: $name,
            factor: $factor === '' ? '1.0000' : $factor,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $slug], error: $result->error()->message);
        }

        return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $slug], success: 'Price list created.');
    }
}
