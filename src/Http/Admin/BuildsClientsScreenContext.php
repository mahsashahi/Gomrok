<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermissions;
use Gomrok\Modules\Admin\Application\Clients\ClientsScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Http\AdminContext;

/**
 * Builds the `clients.html.twig` render context — shared by the plain `GET`
 * action and the create-client / issue-key actions, which render the screen
 * directly (200, not a redirect) on success so a one-time plaintext API key
 * never has to travel through a URL (see {@see RedirectsToClients}).
 */
trait BuildsClientsScreenContext
{
    /**
     * @param array{plaintext_token: string, key_id: string}|null $newApiKey
     *
     * @return array<string, mixed>
     */
    private function clientsScreenContext(
        AdminContext $context,
        ClientsScreenHandler $screen,
        ReferenceCatalog $currencies,
        string $filter,
        ?int $selectedId,
        string $currentPath,
        ?string $error,
        ?string $success,
        ?array $newApiKey = null,
    ): array {
        $role = AdminRole::from($context->admin()->role);

        return [
            'admin' => $context->admin(),
            'active_nav' => 'clients',
            // This screen manages every client, so — unlike Packaging/Providers/
            // Vouchers — it has no "active client" to scope the sidebar switcher to.
            'clients' => [],
            'active_client' => null,
            'screen' => $screen->build($filter, $selectedId),
            'current_path' => $currentPath,
            'error' => $error,
            'success' => $success,
            'permissions' => array_map(static fn ($p) => $p->value, AdminPermissions::for($role)),
            'new_api_key' => $newApiKey,
            // Controlled currency/country combo/select sources (neither is ever
            // free text in the admin UI) — see partials/currency-select.html.twig
            // and partials/country-select.html.twig.
            'currencies' => $currencies->listCurrencies(),
            'countries' => $currencies->listCountries(),
        ];
    }
}
