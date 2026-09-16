<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Pricing\Application\ChangePriceListStatus\ChangePriceListStatusHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/packaging/price-lists/{priceListId}/status` (Phase 27
 * Increment B) — the A/B enable/disable toggle on each price-list card.
 */
final readonly class AdminPriceListsStatusAction
{
    use RedirectsToPackaging;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private ChangePriceListStatusHandler $handler,
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

        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);
        if ($activeClient === null) {
            return $this->redirectToPackaging($response, ['tab' => 'groups'], error: 'No active client.');
        }

        $priceListId = (int) $args['priceListId'];
        $body = AdminForm::body($request);
        $enable = AdminForm::str($body, 'action') === 'enable';
        $group = AdminForm::nullableStr($body, 'group');

        $result = $enable
            ? $this->handler->enable($priceListId, $activeClient->id, $this->context->admin()->id)
            : $this->handler->disable($priceListId, $activeClient->id, $this->context->admin()->id);

        if ($result->isErr()) {
            return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $group], error: $result->error()->message);
        }

        return $this->redirectToPackaging($response, ['tab' => 'groups', 'group' => $group], success: $enable ? 'List enabled.' : 'List disabled.');
    }
}
