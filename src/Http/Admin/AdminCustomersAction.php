<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\Customers\CustomersListHandler;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/customers` (Phase 27) — one row per distinct `client_user_ref`
 * for the active client, with identity/provider references and
 * subscriptions expandable per row.
 */
final readonly class AdminCustomersAction
{
    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private CustomersListHandler $customers,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentPath = $request->getUri()->getPath() . ($request->getUri()->getQuery() !== '' ? '?' . $request->getUri()->getQuery() : '');
        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);

        if ($activeClient === null) {
            return $this->view->render($response, 'customers.html.twig', [
                'admin' => $this->context->admin(),
                'active_nav' => 'customers',
                'clients' => [],
                'active_client' => null,
                'customers' => null,
                'current_path' => $currentPath,
            ]);
        }

        $query = $request->getQueryParams();
        $search = \is_string($query['q'] ?? null) ? $query['q'] : null;

        $result = $this->customers->forClient($activeClient->id, $search);

        return $this->view->render($response, 'customers.html.twig', [
            'admin' => $this->context->admin(),
            'active_nav' => 'customers',
            'clients' => $this->clients->all(),
            'active_client' => $activeClient,
            'customers' => $result,
            'current_query' => $search,
            'current_path' => $currentPath,
        ]);
    }
}
