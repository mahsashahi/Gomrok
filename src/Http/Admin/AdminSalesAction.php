<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\Sales\SalesListHandler;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/sales` (Phase 27) — the Sales screen: every payment for the
 * active client, status stat-tabs, and each row's expandable event timeline.
 */
final readonly class AdminSalesAction
{
    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private SalesListHandler $sales,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentPath = $request->getUri()->getPath() . ($request->getUri()->getQuery() !== '' ? '?' . $request->getUri()->getQuery() : '');
        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);

        if ($activeClient === null) {
            return $this->view->render($response, 'sales.html.twig', [
                'admin' => $this->context->admin(),
                'active_nav' => 'sales',
                'clients' => [],
                'active_client' => null,
                'sales' => null,
                'current_path' => $currentPath,
            ]);
        }

        $query = $request->getQueryParams();
        $status = \is_string($query['status'] ?? null) && $query['status'] !== '' ? $query['status'] : null;

        $result = $this->sales->forClient($activeClient->id, $status);

        return $this->view->render($response, 'sales.html.twig', [
            'admin' => $this->context->admin(),
            'active_nav' => 'sales',
            'clients' => $this->clients->all(),
            'active_client' => $activeClient,
            'sales' => $result,
            'current_status' => $status,
            'current_path' => $currentPath,
        ]);
    }
}
