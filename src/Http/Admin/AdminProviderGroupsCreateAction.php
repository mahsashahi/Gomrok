<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Providers\Application\CreateProviderGroup\CreateProviderGroupCommand;
use Gomrok\Modules\Providers\Application\CreateProviderGroup\CreateProviderGroupHandler;
use Gomrok\Modules\Providers\Application\CreateProviderGroup\CreateProviderGroupResult;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/providers/groups` (Phase 27) — the "Create routing group"
 * modal's submit target. Countries / purchase types / methods are configured
 * separately via `ConfigureProviderGroupHandler`, after creation — mirrors
 * how {@see AdminGroupsCreateAction} handles pricing groups.
 */
final readonly class AdminProviderGroupsCreateAction
{
    use RedirectsToProviders;

    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private CreateProviderGroupHandler $handler,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::ProviderConfigsCreate)) {
            return AdminPermissionGuard::deny($response);
        }

        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);
        if ($activeClient === null) {
            return $this->redirectToProviders($response, ['tab' => 'groups'], error: 'No active client.');
        }

        $body = AdminForm::body($request);
        $name = AdminForm::str($body, 'name');
        $slug = AdminForm::nullableStr($body, 'slug');
        $isDefault = AdminForm::checked($body, 'is_default');
        $deviceType = AdminForm::nullableStr($body, 'device_type');
        $currencyCode = AdminForm::nullableStr($body, 'currency_code');

        $result = $this->handler->handle(new CreateProviderGroupCommand(
            clientId: $activeClient->id,
            name: $name,
            isDefault: $isDefault,
            slug: $slug,
            deviceType: $deviceType,
            currencyCode: $currencyCode,
            actorId: $this->context->admin()->id,
        ));

        if ($result->isErr()) {
            return $this->redirectToProviders($response, ['tab' => 'groups'], error: $result->error()->message);
        }

        $value = $result->value();
        \assert($value instanceof CreateProviderGroupResult);

        return $this->redirectToProviders($response, ['tab' => 'groups', 'group' => $value->slug], success: 'Routing group created.');
    }
}
