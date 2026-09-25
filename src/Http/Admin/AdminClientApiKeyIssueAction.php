<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\Clients\ClientsScreenHandler;
use Gomrok\Modules\Clients\Application\IssueApiKey\IssueApiKeyCommand;
use Gomrok\Modules\Clients\Application\IssueApiKey\IssueApiKeyHandler;
use Gomrok\Modules\Clients\Application\IssueApiKey\IssueApiKeyResult;
use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminModalReopen;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/clients/{clientId}/api-keys` (Phase 27) — the "Issue API key"
 * modal's submit target. Same one-time-plaintext constraint as
 * {@see AdminClientsCreateAction}: renders the screen directly on success
 * instead of redirecting, so the new key's plaintext token never appears in a
 * URL.
 */
final readonly class AdminClientApiKeyIssueAction
{
    use RedirectsToClients;
    use BuildsClientsScreenContext;

    public function __construct(
        private AdminContext $context,
        private ClientsScreenHandler $screen,
        private IssueApiKeyHandler $handler,
        private ReferenceCatalog $currencies,
        private ViewRenderer $view,
        private AdminClientsAction $screenAction,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::ClientApiKeysCreate)) {
            return AdminPermissionGuard::deny($response);
        }

        $clientId = (int) $args['clientId'];
        $body = AdminForm::body($request);
        $environment = AdminForm::str($body, 'environment', 'test');
        $label = AdminForm::nullableStr($body, 'label');
        $submittedValues = ['client_id' => $clientId, 'environment' => $environment, 'label' => $label ?? ''];

        $result = $this->handler->handle(new IssueApiKeyCommand(
            clientId: $clientId,
            prefix: $environment === 'live' ? ApiKeyPrefix::Live : ApiKeyPrefix::Test,
            label: $label,
        ));

        if ($result->isErr()) {
            return $this->screenAction->reopen($request, $response, ['client' => $clientId], new AdminModalReopen('issue-key', $submittedValues, $result->error()->message));
        }

        $value = $result->value();
        \assert($value instanceof IssueApiKeyResult);

        $currentPath = $request->getUri()->getPath();

        return $this->view->render($response, 'clients.html.twig', $this->clientsScreenContext(
            $this->context,
            $this->screen,
            $this->currencies,
            'all',
            $clientId,
            $currentPath,
            null,
            'API key issued.',
            ['plaintext_token' => $value->plaintextToken, 'key_id' => $value->keyId],
        ));
    }
}
