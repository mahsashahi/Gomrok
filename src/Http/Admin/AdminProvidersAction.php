<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermissions;
use Gomrok\Modules\Admin\Application\Providers\AccountsTabHandler;
use Gomrok\Modules\Admin\Application\Providers\GroupsTabHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\ProviderCatalog;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/providers` (Phase 27) — the Providers screen's two tabs
 * (Accounts, By-groups), switched via `?tab=`. Mirrors
 * {@see AdminPackagingAction}'s shareable-URL master-detail shape.
 */
final readonly class AdminProvidersAction
{
    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private ProviderCatalog $providerTypes,
        private ProviderAccountDirectory $providerAccounts,
        private AccountsTabHandler $accountsTab,
        private GroupsTabHandler $groupsTab,
        private ReferenceCatalog $currencies,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentPath = $request->getUri()->getPath() . ($request->getUri()->getQuery() !== '' ? '?' . $request->getUri()->getQuery() : '');
        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);
        $query = $request->getQueryParams();
        $error = \is_string($query['error'] ?? null) ? $query['error'] : null;
        $success = \is_string($query['success'] ?? null) ? $query['success'] : null;
        $permissions = $this->permissionValues();

        if ($activeClient === null) {
            return $this->view->render($response, 'providers.html.twig', [
                'admin' => $this->context->admin(),
                'active_nav' => 'providers',
                'clients' => [],
                'active_client' => null,
                'accounts_tab' => null,
                'groups_tab' => null,
                'current_path' => $currentPath,
                'error' => $error,
                'success' => $success,
                'permissions' => $permissions,
                'provider_types' => [],
                'available_accounts' => [],
                'currencies' => $this->currencies->listCurrencies(),
                'countries' => $this->currencies->listCountries(),
            ]);
        }

        $tab = \is_string($query['tab'] ?? null) && $query['tab'] === 'groups' ? 'groups' : 'accounts';
        $accountSlug = \is_string($query['account'] ?? null) ? $query['account'] : null;
        $groupSlug = \is_string($query['group'] ?? null) ? $query['group'] : null;

        $accountsTabResult = $tab === 'accounts' ? $this->accountsTab->forClient($activeClient->id, $accountSlug) : null;
        $groupsTabResult = $tab === 'groups' ? $this->groupsTab->forClient($activeClient->id, $groupSlug) : null;

        return $this->view->render($response, 'providers.html.twig', [
            'admin' => $this->context->admin(),
            'active_nav' => 'providers',
            'clients' => $this->clients->all(),
            'active_client' => $activeClient,
            'active_tab' => $tab,
            'accounts_tab' => $accountsTabResult,
            'groups_tab' => $groupsTabResult,
            'current_path' => $currentPath,
            'error' => $error,
            'success' => $success,
            'permissions' => $permissions,
            'provider_types' => $this->providerTypes->all(),
            'available_accounts' => $this->providerAccounts->forClient($activeClient->id),
            'currencies' => $this->currencies->listCurrencies(),
            'countries' => $this->currencies->listCountries(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function permissionValues(): array
    {
        $role = AdminRole::from($this->context->admin()->role);

        return array_map(static fn ($p) => $p->value, AdminPermissions::for($role));
    }
}
