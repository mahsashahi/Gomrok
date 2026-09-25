<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermissions;
use Gomrok\Modules\Admin\Application\Vouchers\VouchersScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminModalReopen;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/vouchers` (Phase 27) — the Vouchers screen: a master-detail over
 * the active client's vouchers, with discount configuration, eligibility
 * rules, usage caps and redemption history. `?voucher=<code>` selects one.
 *
 * {@see render()} is also the reopen target for every Vouchers write
 * action's validation failure (`.claude/docs/Ui.md`'s validation-preserving
 * forms rule): instead of redirecting and losing the submitted form, a write
 * action builds a synthetic request carrying the same `?voucher=` the
 * redirect would have used, and calls {@see reopen()} with an
 * {@see AdminModalReopen} so the exact same screen re-renders with the
 * failed modal reopened and the submitted values still in it.
 */
final readonly class AdminVouchersAction
{
    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private VouchersScreenHandler $screen,
        private ReferenceCatalog $currencies,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, null);
    }

    public function render(ServerRequestInterface $request, ResponseInterface $response, ?AdminModalReopen $reopen): ResponseInterface
    {
        $currentPath = $request->getUri()->getPath() . ($request->getUri()->getQuery() !== '' ? '?' . $request->getUri()->getQuery() : '');
        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);
        $query = $request->getQueryParams();
        $error = \is_string($query['error'] ?? null) ? $query['error'] : null;
        $success = \is_string($query['success'] ?? null) ? $query['success'] : null;
        $permissions = $this->permissionValues();
        $status = $reopen !== null ? 422 : 200;

        if ($activeClient === null) {
            return $this->view->render($response, 'vouchers.html.twig', [
                'admin' => $this->context->admin(),
                'active_nav' => 'vouchers',
                'clients' => [],
                'active_client' => null,
                'screen' => null,
                'current_path' => $currentPath,
                'error' => $error,
                'success' => $success,
                'permissions' => $permissions,
                'currencies' => $this->currencies->listCurrencies(),
                'countries' => $this->currencies->listCountries(),
                'reopen_modal' => $reopen,
            ], $status);
        }

        $code = \is_string($query['voucher'] ?? null) ? $query['voucher'] : null;

        return $this->view->render($response, 'vouchers.html.twig', [
            'admin' => $this->context->admin(),
            'active_nav' => 'vouchers',
            'clients' => $this->clients->all(),
            'active_client' => $activeClient,
            'screen' => $this->screen->forClient($activeClient->id, $code),
            'current_path' => $currentPath,
            'error' => $error,
            'success' => $success,
            'permissions' => $permissions,
            'currencies' => $this->currencies->listCurrencies(),
            'countries' => $this->currencies->listCountries(),
            'reopen_modal' => $reopen,
        ], $status);
    }

    /**
     * @param array<string, string|int|null> $queryParams
     */
    public function reopen(ServerRequestInterface $request, ResponseInterface $response, array $queryParams, AdminModalReopen $reopen): ResponseInterface
    {
        $query = array_filter($queryParams, static fn (mixed $v): bool => $v !== null);
        $uri = $request->getUri()->withQuery(http_build_query($query));

        return $this->render($request->withUri($uri), $response, $reopen);
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
