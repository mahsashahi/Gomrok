<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermissions;
use Gomrok\Modules\Admin\Application\Packaging\GroupsTabHandler;
use Gomrok\Modules\Admin\Application\Packaging\PackagesTabHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/packaging` (Phase 27 Increment A — read-only) — the Packaging
 * & Pricing screen's two tabs (Packages, Pricing groups), switched via
 * `?tab=`. Each tab's own master-detail selection is a separate query param
 * (`?package=` / `?group=&list=`) so a link to one detail view is shareable.
 */
final readonly class AdminPackagingAction
{
    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private ProviderAccountDirectory $providerAccounts,
        private PackagesTabHandler $packagesTab,
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
            return $this->view->render($response, 'packaging.html.twig', [
                'admin' => $this->context->admin(),
                'active_nav' => 'packaging',
                'clients' => [],
                'active_client' => null,
                'packages_tab' => null,
                'groups_tab' => null,
                'current_path' => $currentPath,
                'error' => $error,
                'success' => $success,
                'permissions' => $permissions,
                'provider_accounts' => [],
                'currencies' => $this->currencies->listCurrencies(),
                'countries' => $this->currencies->listCountries(),
            ]);
        }

        $tab = \is_string($query['tab'] ?? null) && $query['tab'] === 'groups' ? 'groups' : 'packages';

        $packageCode = \is_string($query['package'] ?? null) ? $query['package'] : null;
        $groupSlug = \is_string($query['group'] ?? null) ? $query['group'] : null;
        $priceListId = \is_string($query['list'] ?? null) && ctype_digit($query['list']) ? (int) $query['list'] : null;

        $packagesTabResult = $tab === 'packages' ? $this->packagesTab->forClient($activeClient->id, $packageCode) : null;
        $groupsTabResult = $tab === 'groups' ? $this->groupsTab->forClient($activeClient->id, $groupSlug, $priceListId) : null;

        return $this->view->render($response, 'packaging.html.twig', [
            'admin' => $this->context->admin(),
            'active_nav' => 'packaging',
            'clients' => $this->clients->all(),
            'active_client' => $activeClient,
            'active_tab' => $tab,
            'packages_tab' => $packagesTabResult,
            'groups_tab' => $groupsTabResult,
            'current_path' => $currentPath,
            'error' => $error,
            'success' => $success,
            'permissions' => $permissions,
            'provider_accounts' => $this->providerAccounts->forClient($activeClient->id),
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
