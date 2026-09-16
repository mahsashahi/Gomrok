<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\DisableClient\DisableClientCommand;
use Gomrok\Modules\Clients\Application\DisableClient\DisableClientHandler;
use Gomrok\Modules\Clients\Application\EnableClient\EnableClientCommand;
use Gomrok\Modules\Clients\Application\EnableClient\EnableClientHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/clients/{clientId}/status` (Phase 27) — enable/disable. There
 * is no separate `clients.disable` permission key (unlike vouchers'), so both
 * directions are gated on `clients.update`.
 */
final readonly class AdminClientsStatusAction
{
    use RedirectsToClients;

    public function __construct(
        private AdminContext $context,
        private DisableClientHandler $disableHandler,
        private EnableClientHandler $enableHandler,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::ClientsUpdate)) {
            return AdminPermissionGuard::deny($response);
        }

        $clientId = (int) $args['clientId'];
        $body = AdminForm::body($request);
        $enable = AdminForm::str($body, 'action') === 'enable';

        $result = $enable
            ? $this->enableHandler->handle(new EnableClientCommand($clientId))
            : $this->disableHandler->handle(new DisableClientCommand($clientId, reason: null, disabledBy: $this->context->admin()->id));

        if ($result->isErr()) {
            return $this->redirectToClients($response, ['client' => $clientId], error: $result->error()->message);
        }

        return $this->redirectToClients($response, ['client' => $clientId], success: $enable ? 'Client enabled.' : 'Client disabled.');
    }
}
