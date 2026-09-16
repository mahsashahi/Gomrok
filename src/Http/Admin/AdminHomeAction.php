<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\Dashboard\HomeDashboardHandler;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin` (Phase 27) — the Home screen: today's KPIs, a sparkline, the
 * period-tabbed overview stats, and recent payments — all scoped to
 * whichever client the switcher currently shows.
 */
final readonly class AdminHomeAction
{
    public function __construct(
        private AdminContext $context,
        private ClientDirectory $clients,
        private HomeDashboardHandler $dashboard,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentPath = $request->getUri()->getPath() . ($request->getUri()->getQuery() !== '' ? '?' . $request->getUri()->getQuery() : '');
        $activeClient = AdminActiveClientCookie::resolve($request, $this->clients);
        if ($activeClient === null) {
            return $this->view->render($response, 'home.html.twig', [
                'admin' => $this->context->admin(),
                'active_nav' => 'home',
                'clients' => [],
                'active_client' => null,
                'dashboard' => null,
                'current_path' => $currentPath,
            ]);
        }

        $query = $request->getQueryParams();
        $period = \is_string($query['period'] ?? null) ? $query['period'] : 'today';

        $dashboard = $this->dashboard->forClient($activeClient->id, $period);

        return $this->view->render($response, 'home.html.twig', [
            'admin' => $this->context->admin(),
            'active_nav' => 'home',
            'clients' => $this->clients->all(),
            'active_client' => $activeClient,
            'dashboard' => $dashboard,
            'current_path' => $currentPath,
        ]);
    }
}
