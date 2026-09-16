<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Clients\Application\RevokeApiKey\RevokeApiKeyCommand;
use Gomrok\Modules\Clients\Application\RevokeApiKey\RevokeApiKeyHandler;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/clients/{clientId}/api-keys/{keyId}/revoke` (Phase 27) —
 * terminal; a revoked key is never reactivated (issue a new one instead).
 */
final readonly class AdminClientApiKeyRevokeAction
{
    use RedirectsToClients;

    public function __construct(
        private AdminContext $context,
        private RevokeApiKeyHandler $handler,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::ClientApiKeysRevoke)) {
            return AdminPermissionGuard::deny($response);
        }

        $clientId = (int) $args['clientId'];
        $keyId = $args['keyId'];

        $result = $this->handler->handle(new RevokeApiKeyCommand($keyId, $this->context->admin()->id));
        if ($result->isErr()) {
            return $this->redirectToClients($response, ['client' => $clientId], error: $result->error()->message);
        }

        return $this->redirectToClients($response, ['client' => $clientId], success: 'API key revoked.');
    }
}
