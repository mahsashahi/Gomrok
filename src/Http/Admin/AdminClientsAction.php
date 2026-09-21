<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\Clients\ClientsScreenHandler;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/clients` (Phase 27 — "Clients (stat tabs + New client modal)").
 * Unlike Packaging/Providers/Vouchers this screen is not scoped to the admin
 * panel's active-client switcher — it lists every client, since managing
 * clients themselves is what it's for. `?filter=all|live|disabled` drives the
 * stat tabs; `?client=<id>` selects a detail row.
 */
final readonly class AdminClientsAction
{
    use BuildsClientsScreenContext;

    public function __construct(
        private AdminContext $context,
        private ClientsScreenHandler $screen,
        private ReferenceCatalog $currencies,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentPath = $request->getUri()->getPath() . ($request->getUri()->getQuery() !== '' ? '?' . $request->getUri()->getQuery() : '');
        $query = $request->getQueryParams();

        $filter = \is_string($query['filter'] ?? null) && \in_array($query['filter'], ['live', 'disabled'], true) ? $query['filter'] : 'all';
        $selectedId = \is_string($query['client'] ?? null) && ctype_digit($query['client']) ? (int) $query['client'] : null;
        $error = \is_string($query['error'] ?? null) ? $query['error'] : null;
        $success = \is_string($query['success'] ?? null) ? $query['success'] : null;

        return $this->view->render($response, 'clients.html.twig', $this->clientsScreenContext(
            $this->context,
            $this->screen,
            $this->currencies,
            $filter,
            $selectedId,
            $currentPath,
            $error,
            $success,
        ));
    }
}
