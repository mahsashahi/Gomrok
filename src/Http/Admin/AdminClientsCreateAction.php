<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\Clients\ClientsScreenHandler;
use Gomrok\Modules\Clients\Application\CreateClient\CreateClientCommand;
use Gomrok\Modules\Clients\Application\CreateClient\CreateClientHandler;
use Gomrok\Modules\Clients\Application\CreateClient\CreateClientResult;
use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminPermissionGuard;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/clients` (Phase 27) — the "New client" modal's submit target.
 * `CreateClientHandler` mints the client's first API key in the same
 * transaction; that plaintext token is available exactly once
 * ({@see CreateClientResult}), so on success this renders the Clients screen
 * directly (200) with it, rather than redirecting — a redirect would have to
 * carry the secret in the URL, which is never acceptable (CLAUDE.md: no
 * secrets logged or exposed in plain text more broadly than necessary).
 *
 * The design reference's copy about an auto-created default pricing group
 * does not reflect a real capability — `CreateClientHandler` creates no
 * `PricingGroup` — so that claim is omitted from this screen's modal text
 * rather than silently implemented or left as a false promise.
 */
final readonly class AdminClientsCreateAction
{
    use RedirectsToClients;
    use BuildsClientsScreenContext;

    public function __construct(
        private AdminContext $context,
        private ClientsScreenHandler $screen,
        private CreateClientHandler $handler,
        private ReferenceCatalog $currencies,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!AdminPermissionGuard::allows($this->context, AdminPermission::ClientsCreate)) {
            return AdminPermissionGuard::deny($response);
        }

        $body = AdminForm::body($request);
        $slug = AdminForm::str($body, 'slug');
        $environment = AdminForm::str($body, 'environment', 'test');

        $result = $this->handler->handle(new CreateClientCommand(
            slug: $slug,
            name: AdminForm::str($body, 'name'),
            defaultCurrency: AdminForm::str($body, 'default_currency', 'USD'),
            defaultCountry: AdminForm::nullableStr($body, 'default_country'),
            timezone: AdminForm::str($body, 'timezone', 'UTC'),
            firstKeyPrefix: $environment === 'live' ? ApiKeyPrefix::Live : ApiKeyPrefix::Test,
        ));

        if ($result->isErr()) {
            return $this->redirectToClients($response, [], error: $result->error()->message);
        }

        $value = $result->value();
        \assert($value instanceof CreateClientResult);

        $currentPath = $request->getUri()->getPath();

        return $this->view->render($response, 'clients.html.twig', $this->clientsScreenContext(
            $this->context,
            $this->screen,
            $this->currencies,
            'all',
            $value->clientId,
            $currentPath,
            null,
            'Client created.',
            ['plaintext_token' => $value->plaintextApiKey, 'key_id' => $value->keyId],
        ));
    }
}
