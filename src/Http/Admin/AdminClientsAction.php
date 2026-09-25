<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\Clients\ClientsScreenHandler;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AdminModalReopen;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/clients` (Phase 27 — "Clients (stat tabs + New client modal)").
 * Unlike Packaging/Providers/Vouchers this screen is not scoped to the admin
 * panel's active-client switcher — it lists every client, since managing
 * clients themselves is what it's for. `?filter=all|live|disabled` drives the
 * stat tabs; `?client=<id>` selects a detail row.
 *
 * {@see render()} is also the reopen target for every Clients write action's
 * validation failure (`.claude/docs/Ui.md`'s validation-preserving forms
 * rule): instead of redirecting and losing the submitted form, a write
 * action calls {@see reopen()} with an {@see AdminModalReopen} so the exact
 * same screen re-renders with the failed modal reopened and the submitted
 * values still in it.
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
        return $this->render($request, $response, null);
    }

    public function render(ServerRequestInterface $request, ResponseInterface $response, ?AdminModalReopen $reopen): ResponseInterface
    {
        $currentPath = $request->getUri()->getPath() . ($request->getUri()->getQuery() !== '' ? '?' . $request->getUri()->getQuery() : '');
        $query = $request->getQueryParams();

        $filter = \is_string($query['filter'] ?? null) && \in_array($query['filter'], ['live', 'disabled'], true) ? $query['filter'] : 'all';
        $selectedId = \is_string($query['client'] ?? null) && ctype_digit($query['client']) ? (int) $query['client'] : null;
        $error = \is_string($query['error'] ?? null) ? $query['error'] : null;
        $success = \is_string($query['success'] ?? null) ? $query['success'] : null;

        $context = $this->clientsScreenContext(
            $this->context,
            $this->screen,
            $this->currencies,
            $filter,
            $selectedId,
            $currentPath,
            $error,
            $success,
        );
        $context['reopen_modal'] = $reopen;

        return $this->view->render($response, 'clients.html.twig', $context, $reopen !== null ? 422 : 200);
    }

    /**
     * A write action's validation-failure return: re-renders this screen at
     * the given `?filter=&client=` selection (the same params
     * {@see RedirectsToClients::redirectToClients()} would have put in a
     * redirect Location) with the failed modal reopened.
     *
     * @param array<string, string|int|null> $queryParams
     */
    public function reopen(ServerRequestInterface $request, ResponseInterface $response, array $queryParams, AdminModalReopen $reopen): ResponseInterface
    {
        $query = array_filter($queryParams, static fn (mixed $v): bool => $v !== null);
        $uri = $request->getUri()->withQuery(http_build_query($query));

        return $this->render($request->withUri($uri), $response, $reopen);
    }
}
