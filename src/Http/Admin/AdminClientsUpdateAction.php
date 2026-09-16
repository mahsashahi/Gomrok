<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\UpdateClient\UpdateClientCommand;
use Gomrok\Modules\Clients\Application\UpdateClient\UpdateClientHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/clients/{clientId}` (Phase 27) — the "Edit client" modal's
 * submit target: name and market defaults. `slug` is immutable and not part
 * of this form.
 */
final readonly class AdminClientsUpdateAction
{
    use RedirectsToClients;

    public function __construct(
        private AdminContext $context,
        private UpdateClientHandler $handler,
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
        $defaultCountry = AdminForm::nullableStr($body, 'default_country');

        $result = $this->handler->handle(new UpdateClientCommand(
            clientId: $clientId,
            name: AdminForm::str($body, 'name'),
            defaultCurrency: AdminForm::str($body, 'default_currency'),
            defaultCountry: $defaultCountry,
            clearDefaultCountry: $defaultCountry === null,
            timezone: AdminForm::str($body, 'timezone'),
        ));

        if ($result->isErr()) {
            return $this->redirectToClients($response, ['client' => $clientId], error: $result->error()->message);
        }

        return $this->redirectToClients($response, ['client' => $clientId], success: 'Client updated.');
    }
}
