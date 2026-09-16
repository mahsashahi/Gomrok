<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\AdminPermissions;
use Gomrok\Modules\Admin\Application\Vouchers\VouchersScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/vouchers` (Phase 27) — the Vouchers screen: a master-detail over
 * the active client's vouchers, with discount configuration, eligibility
 * rules, usage caps and redemption history. `?voucher=<code>` selects one.
 */
final readonly class AdminVouchersAction
{
    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private VouchersScreenHandler $screen,
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
            ]);
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
